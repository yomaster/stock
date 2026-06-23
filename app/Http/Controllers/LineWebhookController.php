<?php

namespace App\Http\Controllers;

use App\Jobs\AskStockJob;
use App\Models\News;
use App\Models\Portfolio;
use App\Models\Stock;
use App\Services\InvestmentService;
use App\Services\LineService;
use App\Services\PortfolioService;
use App\Services\SettingsService;
use Illuminate\Http\Request;

class LineWebhookController extends Controller
{
    public function __construct(
        private LineService $line,
        private SettingsService $settings,
        private InvestmentService $investment,
        private PortfolioService $portfolio,
    ) {}

    public function handle(Request $request)
    {
        $rawBody   = $request->getContent();
        $signature = $request->header('X-Line-Signature');

        // ตรวจลายเซ็นก่อนเสมอ — กัน request ปลอม
        if (!$this->line->verifySignature($rawBody, $signature)) {
            return response('Invalid signature', 403);
        }

        $events = $request->input('events', []);

        foreach ($events as $event) {
            // source id (user/group/room) ใช้ push กลับ + จับคู่กับบัญชีผู้ใช้ผ่าน line_user_id
            $sourceId = $this->extractSourceId($event);

            if (($event['type'] ?? '') === 'message' && ($event['message']['type'] ?? '') === 'text') {
                $text       = trim($event['message']['text'] ?? '');
                $replyToken = $event['replyToken'] ?? null;
                $this->handleCommand($text, $replyToken, $sourceId);
            }
        }

        // ตอบ 200 เร็วเสมอ (งานหนักทำใน job)
        return response('OK', 200);
    }

    private function extractSourceId(array $event): ?string
    {
        $src = $event['source'] ?? [];
        return $src['userId'] ?? $src['groupId'] ?? $src['roomId'] ?? null;
    }

    /** หา user ที่ผูก LINE id นี้ไว้ (null = ยังไม่ผูกบัญชี) */
    private function userFromSource(?string $sourceId): ?\App\Models\User
    {
        if (!$sourceId) {
            return null;
        }
        return \App\Models\User::where('line_user_id', $sourceId)->first();
    }

    /** /link รหัส — จับคู่ LINE id นี้กับบัญชีผู้ใช้ (รหัสสร้างจากหน้าโปรไฟล์) */
    private function cmdLink(array $parts, string $replyToken, ?string $sourceId): void
    {
        if (!$sourceId) {
            $this->line->reply($replyToken, "ไม่สามารถระบุ LINE ID ของคุณได้");
            return;
        }
        if (count($parts) < 2) {
            $this->line->reply($replyToken, "ใช้: /link รหัส6หลัก\nสร้างรหัสได้ที่หน้าโปรไฟล์ในเว็บ");
            return;
        }

        $code = strtoupper(trim($parts[1]));
        $user = \App\Models\User::where('line_link_code', $code)
            ->where('line_link_code_expires_at', '>', now())
            ->first();

        if (!$user) {
            $this->line->reply($replyToken, "รหัสไม่ถูกต้องหรือหมดอายุแล้ว ❌\nสร้างรหัสใหม่ที่หน้าโปรไฟล์");
            return;
        }

        // LINE id นี้ถูกผูกกับบัญชีอื่นอยู่แล้วหรือไม่ (line_user_id unique)
        $taken = \App\Models\User::where('line_user_id', $sourceId)->where('id', '!=', $user->id)->exists();
        if ($taken) {
            $this->line->reply($replyToken, "LINE นี้ถูกผูกกับบัญชีอื่นอยู่แล้ว — ปลดการผูกที่บัญชีนั้นก่อน");
            return;
        }

        $user->forceFill([
            'line_user_id'              => $sourceId,
            'line_link_code'            => null,
            'line_link_code_expires_at' => null,
        ])->save();

        $this->line->reply($replyToken, "✅ ผูกบัญชีสำเร็จ!\nคุณ {$user->name} จะได้รับแจ้งเตือนราคา + สรุปเช้าที่ LINE นี้");
    }

    private function handleCommand(string $text, ?string $replyToken, ?string $sourceId): void
    {
        if (!$replyToken) {
            return;
        }

        $parts   = preg_split('/\s+/', $text);
        $command = strtolower($parts[0] ?? '');

        switch ($command) {
            case '/ask':
                $this->cmdAsk($parts, $replyToken, $sourceId);
                break;
            case '/plan':
                $this->cmdPlan($parts, $replyToken);
                break;
            case '/news':
                $this->cmdNews($parts, $replyToken);
                break;
            case '/portfolio':
            case '/port':
                $this->cmdPortfolio($parts, $replyToken, $sourceId);
                break;
            case '/list':
                $this->cmdList($replyToken, $sourceId);
                break;
            case '/link':
                $this->cmdLink($parts, $replyToken, $sourceId);
                break;
            case '/help':
            case 'help':
            case 'เมนู':
                $this->line->reply($replyToken, $this->helpText());
                break;
            default:
                $this->line->reply($replyToken, "ไม่เข้าใจคำสั่ง 🤔\n\n" . $this->helpText());
        }
    }

    /** /ask SYMBOL — วิเคราะห์ด้วย AI (ทำใน background แล้ว reply กลับ — reply token ฟรี ไม่หักโควตา) */
    private function cmdAsk(array $parts, string $replyToken, ?string $sourceId): void
    {
        if (count($parts) < 2) {
            $this->line->reply($replyToken, "ใช้: /ask SYMBOL\nเช่น /ask NVDA หรือ /ask PTT.BK");
            return;
        }

        if (!$sourceId) {
            $this->line->reply($replyToken, "ไม่สามารถระบุผู้รับผลวิเคราะห์ได้");
            return;
        }

        // แสดง loading animation (จุดเด้งๆ) ระหว่างรอ
        $this->line->startLoading($sourceId, 60);

        // resolve หรือดึงจาก Yahoo ถ้ายังไม่มี + วิเคราะห์ — ทำหลังส่ง 200 (defer)
        // ⚠️ ใช้ reply token (ฟรี) แทน push — AI เสร็จใน ~ไม่กี่วินาที ยังอยู่ในอายุ token (~1 นาที)
        //    ข้อแลก: ถ้า AI ช้าเกิน ~1 นาที token หมดอายุ ผู้ใช้จะไม่ได้รับข้อความ
        $input = $parts[1];
        defer(function () use ($input, $replyToken) {
            $stock = $this->resolveOrImport($input);
            if (!$stock) {
                app(LineService::class)->reply($replyToken, "ไม่พบหุ้น " . strtoupper($input)
                    . " บน Yahoo Finance\nลองใส่ suffix ให้ถูก เช่น PTT.BK (ไทย) หรือ TSM (US)");
                return;
            }
            AskStockJob::dispatchSync($replyToken, $stock->symbol);
        });
    }

    /**
     * หา Stock ในระบบ — ถ้าไม่มี ดึงจาก Yahoo อัตโนมัติ (ฟรี ไม่กิน AI token)
     * ลอง symbol ตามที่พิมพ์ก่อน แล้วลองเติม .BK (หุ้นไทย)
     */
    private function resolveOrImport(string $input): ?Stock
    {
        $sym = strtoupper(trim($input));

        // มีในระบบแล้ว + มีราคาจริง (ลองตามพิมพ์ → .BK)
        if ($stock = $this->stockWithPrices($sym)) {
            return $stock;
        }
        if ($stock = $this->stockWithPrices($sym . '.BK')) {
            return $stock;
        }

        // ดึงจาก Yahoo — ลอง symbol ตามพิมพ์
        $this->importSymbol($sym);
        if ($stock = $this->stockWithPrices($sym)) {
            return $stock;
        }

        // ไม่ได้ราคา → ลองเติม .BK (เผื่อหุ้นไทยลืม suffix)
        if (!str_ends_with($sym, '.BK')) {
            $this->importSymbol($sym . '.BK');
            if ($stock = $this->stockWithPrices($sym . '.BK')) {
                return $stock;
            }
        }

        return null;
    }

    /** Stock ที่มีราคาในระบบจริง (ไม่ใช่แค่ record เปล่า) */
    private function stockWithPrices(string $symbol): ?Stock
    {
        return Stock::where('symbol', $symbol)->whereHas('prices')->first();
    }

    /** ดึงราคาจาก Yahoo — ถ้าได้ record เปล่า (ไม่มีราคา) ลบทิ้งกันขยะ */
    private function importSymbol(string $symbol): void
    {
        \Illuminate\Support\Facades\Artisan::call('app:fetch-stock-data', ['symbol' => $symbol, '--years' => 5]);
        Stock::where('symbol', $symbol)->whereDoesntHave('prices')->delete();
    }

    /** /plan SYMBOL เงินต่อเดือน ปี — จำลอง DCA ย้อนหลัง */
    private function cmdPlan(array $parts, string $replyToken): void
    {
        if (count($parts) < 4) {
            $this->line->reply($replyToken, "ใช้: /plan SYMBOL เงินต่อเดือน ปี\nเช่น /plan NVDA 5000 10");
            return;
        }

        $stock = $this->resolveStock($parts[1]);
        if (!$stock) {
            $this->line->reply($replyToken, $this->notFoundText($parts[1]));
            return;
        }

        $monthly = (float) str_replace(',', '', $parts[2]);
        $years   = (int) $parts[3];

        if ($monthly <= 0 || $years <= 0) {
            $this->line->reply($replyToken, "เงินต่อเดือนและจำนวนปีต้องมากกว่า 0");
            return;
        }

        $r = $this->investment->backtestDCA($stock->symbol, $monthly, $years, 1, true);

        if (!$r['success']) {
            $this->line->reply($replyToken, "คำนวณไม่สำเร็จ: " . ($r['error'] ?? 'ลองใหม่'));
            return;
        }

        $cur     = $r['currency'];
        $profit  = $r['profit_loss_value'] >= 0;
        $sign    = $profit ? '+' : '';

        // ช่วงข้อมูลจริง — อาจสั้นกว่า $years ที่ขอ ถ้าหุ้นมีราคาใน DB ไม่ครบ
        //   โชว์ช่วงจริง (MM/YYYY) + จำนวนปีที่คำนวณได้จริง แทนการพิมพ์ "{$years} ปี" ตรงๆ ที่หลอกตา
        $months    = (int) ($r['actual_months'] ?? $years * 12);
        $realYears = round($months / 12, 1);
        $startTxt  = \Illuminate\Support\Carbon::parse($r['actual_start'])->format('m/Y');
        $endTxt    = \Illuminate\Support\Carbon::parse($r['actual_end'])->format('m/Y');

        $msg  = "📈 DCA {$stock->symbol}\n";
        $msg .= "ช่วงข้อมูล: {$startTxt} – {$endTxt} (~{$realYears} ปี)\n";
        // เตือนเมื่อข้อมูลจริงสั้นกว่าที่ขอเกิน 1 เดือน — ผู้ใช้จะได้รู้ว่าทำไมเงินสะสมไม่เท่าที่คิด
        if ($months < $years * 12 - 1) {
            $msg .= "⚠️ ขอ {$years} ปี แต่มีข้อมูลแค่ ~{$realYears} ปี\n";
        }
        $msg .= "ลงทุนเดือนละ " . number_format($monthly) . " {$cur}\n";
        $msg .= "━━━━━━━━━━━━━\n";
        $msg .= "เงินลงทุนสะสม: " . number_format($r['total_invested'], 0) . " {$cur}\n";
        $msg .= "เงินปันผลรับ: " . number_format($r['total_dividends_received'], 0) . " {$cur}\n";
        $msg .= "มูลค่าพอร์ตปัจจุบัน: " . number_format($r['portfolio_value'], 0) . " {$cur}\n";
        $msg .= ($profit ? "✅ " : "🔻 ") . "กำไร/ขาดทุน: {$sign}" . number_format($r['profit_loss_value'], 0) . " {$cur} ({$sign}" . number_format($r['profit_loss_percentage'], 1) . "%)";

        $this->line->reply($replyToken, $msg);
    }

    /** /news SYMBOL — ข่าวล่าสุดของหุ้น */
    private function cmdNews(array $parts, string $replyToken): void
    {
        if (count($parts) < 2) {
            $this->line->reply($replyToken, "ใช้: /news SYMBOL\nเช่น /news PTT");
            return;
        }

        $stock = $this->resolveStock($parts[1]);
        if (!$stock) {
            $this->line->reply($replyToken, $this->notFoundText($parts[1]));
            return;
        }

        $news = News::where('symbols', 'like', '%' . $stock->symbol . '%')
            ->orderBy('published_at', 'desc')
            ->limit(5)
            ->get();

        if ($news->isEmpty()) {
            $this->line->reply($replyToken, "ยังไม่มีข่าวของ {$stock->symbol} ในระบบ");
            return;
        }

        $msg = "📰 ข่าวล่าสุด {$stock->symbol}\n━━━━━━━━━━━━━\n";
        foreach ($news as $i => $n) {
            $title = $n->title_th ?? $n->title;
            $msg .= ($i + 1) . ". {$title}\n   ({$n->source})\n";
        }

        $this->line->reply($replyToken, trim($msg));
    }

    /** /portfolio [ชื่อ] — ดูพอร์ต (ไม่ระบุ = ลิสต์ให้เลือก / ระบุ = สถิติ + กราฟ) */
    private function cmdPortfolio(array $parts, string $replyToken, ?string $sourceId): void
    {
        // ต้องผูกบัญชีก่อน — พอร์ตเป็นข้อมูลรายคน
        $user = $this->userFromSource($sourceId);
        if (!$user) {
            $this->line->reply($replyToken, $this->notLinkedText());
            return;
        }

        // ไม่ระบุชื่อ → แสดงรายการพอร์ตเป็น quick reply ให้เลือก (เฉพาะของ user นี้)
        if (count($parts) < 2) {
            $portfolios = $user->portfolios()->orderBy('name')->get();
            if ($portfolios->isEmpty()) {
                $this->line->reply($replyToken, "ยังไม่มีพอร์ต — สร้างได้ที่หน้าเว็บ");
                return;
            }
            $items = [];
            foreach ($portfolios->take(13) as $p) {
                $items[] = [
                    'type'   => 'action',
                    'action' => ['type' => 'message', 'label' => mb_substr($p->name, 0, 20), 'text' => '/portfolio ' . $p->name],
                ];
            }
            $this->line->reply($replyToken, [
                'type'       => 'text',
                'text'       => "📁 เลือกพอร์ตที่ต้องการดู:",
                'quickReply' => ['items' => $items],
            ]);
            return;
        }

        // ระบุชื่อ → หาพอร์ต (เฉพาะของ user นี้; ตรงตัวก่อน แล้ว partial)
        $name = trim(implode(' ', array_slice($parts, 1)));
        $portfolio = $user->portfolios()->where('name', $name)->first()
            ?? $user->portfolios()->where('name', 'like', '%' . $name . '%')->first();

        if (!$portfolio) {
            $this->line->reply($replyToken, "ไม่พบพอร์ต \"{$name}\"\nพิมพ์ /portfolio เพื่อดูรายการ");
            return;
        }

        if (!$sourceId) {
            $this->line->reply($replyToken, "ไม่สามารถระบุผู้รับได้");
            return;
        }

        // คำนวณ + ส่งกราฟใช้เวลา (ดึงราคาสด) → loading + defer + push
        $this->line->startLoading($sourceId, 30);
        $portfolioId = $portfolio->id;
        defer(function () use ($sourceId, $portfolioId) {
            $portfolio = Portfolio::find($portfolioId);
            if (!$portfolio) {
                return;
            }
            $data = $this->portfolio->buildHoldings($portfolio);
            $line = app(LineService::class);

            if (empty($data['holdings'])) {
                $line->push($sourceId, "💼 {$portfolio->name}\nพอร์ตนี้ยังว่าง — เพิ่มหุ้นที่หน้าเว็บก่อน");
                return;
            }

            $alloc = $this->portfolio->groupBySymbol($data['holdings'], $data['total_value_thb']);
            $pl    = $data['total_pl_thb'];
            $sign  = $pl >= 0 ? '+' : '';

            $txt  = "💼 {$portfolio->name}\n━━━━━━━━━━━━━\n";
            $txt .= "มูลค่า: " . number_format($data['total_value_thb'], 0) . " บาท\n";
            $txt .= "เงินลงทุน: " . number_format($data['total_cost_thb'], 0) . " บาท\n";
            $txt .= ($pl >= 0 ? "✅ " : "🔻 ") . "กำไร/ขาดทุน: {$sign}" . number_format($pl, 0)
                . " บาท ({$sign}" . number_format($data['total_pl_percent'], 1) . "%)\n\n";
            $txt .= "📊 สัดส่วน (Top " . min(8, count($alloc)) . "):\n";
            foreach (array_slice($alloc, 0, 8) as $a) {
                $txt .= "• {$a['symbol']} " . number_format($a['allocation'], 1) . "%\n";
            }

            $chartUrl = $this->allocationChartUrl($alloc);

            $line->push($sourceId, [
                ['type' => 'text', 'text' => trim($txt)],
                ['type' => 'image', 'originalContentUrl' => $chartUrl, 'previewImageUrl' => $chartUrl],
            ]);
        });
    }

    /** สร้าง URL กราฟ doughnut สัดส่วนพอร์ตผ่าน QuickChart (ฟรี, คืน PNG) */
    private function allocationChartUrl(array $alloc): string
    {
        $config = [
            'type' => 'doughnut',
            'data' => [
                'labels'   => array_map(fn ($a) => $a['symbol'], $alloc),
                'datasets' => [['data' => array_map(fn ($a) => round($a['value_thb'], 2), $alloc)]],
            ],
            'options' => [
                'plugins' => [
                    'legend' => ['position' => 'right'],
                    'title'  => ['display' => true, 'text' => 'Portfolio Allocation'],
                ],
            ],
        ];
        return 'https://quickchart.io/chart?w=500&h=300&c=' . urlencode(json_encode($config));
    }

    /** /list — หุ้นที่ผู้ใช้ติดตาม (เฉพาะบัญชีที่ผูก LINE แล้ว) */
    private function cmdList(string $replyToken, ?string $sourceId): void
    {
        $user = $this->userFromSource($sourceId);
        if (!$user) {
            $this->line->reply($replyToken, $this->notLinkedText());
            return;
        }

        $symbols = $user->stocks()->orderBy('symbol')->pluck('symbol')->toArray();
        if (empty($symbols)) {
            $this->line->reply($replyToken, "คุณยังไม่ได้ติดตามหุ้นใด — เพิ่มได้ที่หน้าเว็บ");
            return;
        }
        $this->line->reply($replyToken, "📋 หุ้นที่คุณติดตาม:\n" . implode(', ', $symbols));
    }

    /** ข้อความเตือนให้ผูกบัญชีก่อนใช้คำสั่งที่อิงข้อมูลรายคน */
    private function notLinkedText(): string
    {
        return "🔗 ยังไม่ได้ผูกบัญชี LINE กับระบบ\n"
            . "ไปที่หน้าโปรไฟล์ในเว็บ → สร้างรหัสผูกบัญชี → พิมพ์ /link รหัส ที่นี่";
    }

    /**
     * แปลง input ของผู้ใช้เป็น Stock — ลองตรงๆ ก่อน แล้วลองเติม .BK (หุ้นไทย)
     */
    private function resolveStock(string $input): ?Stock
    {
        $sym = strtoupper(trim($input));
        return Stock::where('symbol', $sym)->first()
            ?? Stock::where('symbol', $sym . '.BK')->first();
    }

    private function notFoundText(string $input): string
    {
        return "ไม่พบหุ้น " . strtoupper($input) . " ในระบบ\nพิมพ์ /list เพื่อดูหุ้นที่มี";
    }

    private function helpText(): string
    {
        return "📌 คำสั่งที่ใช้ได้:\n"
            . "/ask SYMBOL — วิเคราะห์ด้วย AI\n"
            . "/plan SYMBOL เงิน/เดือน ปี — จำลอง DCA\n"
            . "/news SYMBOL — ข่าวล่าสุด\n"
            . "/portfolio — ดูพอร์ต + กราฟสัดส่วน\n"
            . "/list — ดูหุ้นที่คุณติดตาม\n"
            . "/link รหัส — ผูกบัญชี LINE กับเว็บ\n\n"
            . "ตัวอย่าง:\n/ask NVDA\n/plan PTT 5000 10\n/portfolio";
    }
}
