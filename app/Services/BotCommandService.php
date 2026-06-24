<?php

namespace App\Services;

use App\Models\News;
use App\Models\Portfolio;
use App\Models\Stock;
use App\Models\User;
use App\Services\Messaging\BotEvent;
use App\Services\Messaging\MessagingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

/**
 * Logic คำสั่งบอท (provider-agnostic) — ย้ายมาจาก LineWebhookController เดิม
 * ทำงานบน MessagingService (LINE/Telegram) + BotEvent ที่ normalize แล้ว
 */
class BotCommandService
{
    public function __construct(
        private InvestmentService $investment,
        private PortfolioService $portfolio,
    ) {}

    /** จุดเข้าหลัก — เรียกจาก webhook controller ของแต่ละ provider */
    public function handle(MessagingService $messaging, BotEvent $event): void
    {
        if ($event->type === 'follow') {
            $messaging->reply($event->replyContext, $this->welcomeText());
            return;
        }
        if ($event->type === 'message' && $event->text !== null && $event->text !== '') {
            $this->dispatch($messaging, $event);
        }
    }

    private function dispatch(MessagingService $m, BotEvent $e): void
    {
        $parts   = preg_split('/\s+/', $e->text);
        $command = strtolower($parts[0] ?? '');

        switch ($command) {
            case '/ask':       $this->cmdAsk($m, $e, $parts); break;
            case '/plan':      $this->cmdPlan($m, $e, $parts); break;
            case '/news':      $this->cmdNews($m, $e, $parts); break;
            case '/portfolio':
            case '/port':      $this->cmdPortfolio($m, $e, $parts); break;
            case '/list':      $this->cmdList($m, $e); break;
            case '/link':      $this->cmdLink($m, $e, $parts); break;
            case '/help':
            case 'help':
            case 'เมนู':       $m->reply($e->replyContext, $this->helpText()); break;
            default:           $m->reply($e->replyContext, "ไม่เข้าใจคำสั่ง 🤔\n\n" . $this->helpText());
        }
    }

    // ───────────────────────── /ask ─────────────────────────

    /** /ask SYMBOL — วิเคราะห์ AI (defer แล้ว reply กลับ — token/chat_id ฟรี) */
    private function cmdAsk(MessagingService $m, BotEvent $e, array $parts): void
    {
        if (count($parts) < 2) {
            $m->reply($e->replyContext, "ใช้: /ask SYMBOL\nเช่น /ask NVDA หรือ /ask PTT.BK");
            return;
        }

        $m->startTyping($e->chatId);

        // resolve/ดึง Yahoo + วิเคราะห์ — ทำหลังส่ง 200 (defer); LINE reply token อายุ ~1 นาที
        $input = $parts[1];
        $ctx   = $e->replyContext;
        defer(function () use ($m, $ctx, $input) {
            $stock = $this->resolveOrImport($input);
            if (!$stock) {
                $m->reply($ctx, "ไม่พบหุ้น " . strtoupper($input)
                    . " บน Yahoo Finance\nลองใส่ suffix ให้ถูก เช่น PTT.BK (ไทย) หรือ TSM (US)");
                return;
            }
            $m->reply($ctx, $this->buildAskMessage($stock));
        });
    }

    /** สร้างข้อความบทวิเคราะห์ AI (ค่ามาตรฐาน: ลงทุน 0 + DCA หมื่น/เดือน 10 ปี) */
    private function buildAskMessage(Stock $stock): string
    {
        $result = $this->investment->projectFutureAI($stock->symbol, 0, 10000, 10);
        if (!$result['success']) {
            return "วิเคราะห์ {$stock->symbol} ไม่สำเร็จ: " . ($result['error'] ?? 'ลองใหม่อีกครั้ง');
        }

        $p = $result['projections'];
        return "📊 บทวิเคราะห์ {$stock->symbol}\n{$stock->name}\n━━━━━━━━━━━━━\n"
            . "ความเสี่ยง: {$result['risk_score']}/10\n\n"
            . "🔮 คาดการณ์ CAGR ต่อปี:\n"
            . "🚀 Bull: " . number_format($p['bull']['cagr'], 1) . "%\n"
            . "📊 Base: " . number_format($p['base']['cagr'], 1) . "%\n"
            . "🐻 Bear: " . number_format($p['bear']['cagr'], 1) . "%\n\n"
            . "💡 " . $result['summary'];
    }

    // ───────────────────────── /plan ─────────────────────────

    private function cmdPlan(MessagingService $m, BotEvent $e, array $parts): void
    {
        if (count($parts) < 4) {
            $m->reply($e->replyContext, "ใช้: /plan SYMBOL เงินต่อเดือน ปี\nเช่น /plan NVDA 5000 10");
            return;
        }

        $stock = $this->resolveStock($parts[1]);
        if (!$stock) {
            $m->reply($e->replyContext, $this->notFoundText($parts[1]));
            return;
        }

        $monthly = (float) str_replace(',', '', $parts[2]);
        $years   = (int) $parts[3];
        if ($monthly <= 0 || $years <= 0) {
            $m->reply($e->replyContext, "เงินต่อเดือนและจำนวนปีต้องมากกว่า 0");
            return;
        }

        $r = $this->investment->backtestDCA($stock->symbol, $monthly, $years, 1, true);
        if (!$r['success']) {
            $m->reply($e->replyContext, "คำนวณไม่สำเร็จ: " . ($r['error'] ?? 'ลองใหม่'));
            return;
        }

        $cur    = $r['currency'];
        $profit = $r['profit_loss_value'] >= 0;
        $sign   = $profit ? '+' : '';

        // ช่วงข้อมูลจริง — อาจสั้นกว่า $years ที่ขอ
        $months    = (int) ($r['actual_months'] ?? $years * 12);
        $realYears = round($months / 12, 1);
        $startTxt  = Carbon::parse($r['actual_start'])->format('m/Y');
        $endTxt    = Carbon::parse($r['actual_end'])->format('m/Y');

        $msg  = "📈 DCA {$stock->symbol}\n";
        $msg .= "ช่วงข้อมูล: {$startTxt} – {$endTxt} (~{$realYears} ปี)\n";
        if ($months < $years * 12 - 1) {
            $msg .= "⚠️ ขอ {$years} ปี แต่มีข้อมูลแค่ ~{$realYears} ปี\n";
        }
        $msg .= "ลงทุนเดือนละ " . number_format($monthly) . " {$cur}\n";
        $msg .= "━━━━━━━━━━━━━\n";
        $msg .= "เงินลงทุนสะสม: " . number_format($r['total_invested'], 0) . " {$cur}\n";
        $msg .= "เงินปันผลรับ: " . number_format($r['total_dividends_received'], 0) . " {$cur}\n";
        $msg .= "มูลค่าพอร์ตปัจจุบัน: " . number_format($r['portfolio_value'], 0) . " {$cur}\n";
        $msg .= ($profit ? "✅ " : "🔻 ") . "กำไร/ขาดทุน: {$sign}" . number_format($r['profit_loss_value'], 0)
            . " {$cur} ({$sign}" . number_format($r['profit_loss_percentage'], 1) . "%)";

        $m->reply($e->replyContext, $msg);
    }

    // ───────────────────────── /news ─────────────────────────

    private function cmdNews(MessagingService $m, BotEvent $e, array $parts): void
    {
        if (count($parts) < 2) {
            $m->reply($e->replyContext, "ใช้: /news SYMBOL\nเช่น /news PTT");
            return;
        }

        $stock = $this->resolveStock($parts[1]);
        if (!$stock) {
            $m->reply($e->replyContext, $this->notFoundText($parts[1]));
            return;
        }

        $news = News::where('symbols', 'like', '%' . $stock->symbol . '%')
            ->orderBy('published_at', 'desc')->limit(5)->get();

        if ($news->isEmpty()) {
            $m->reply($e->replyContext, "ยังไม่มีข่าวของ {$stock->symbol} ในระบบ");
            return;
        }

        $msg = "📰 ข่าวล่าสุด {$stock->symbol}\n━━━━━━━━━━━━━\n";
        foreach ($news as $i => $n) {
            $title = $n->title_th ?? $n->title;
            $msg .= ($i + 1) . ". {$title}\n   ({$n->source})\n";
        }

        $m->reply($e->replyContext, trim($msg));
    }

    // ───────────────────────── /portfolio ─────────────────────────

    private function cmdPortfolio(MessagingService $m, BotEvent $e, array $parts): void
    {
        $user = $this->userFor($m, $e->chatId);
        if (!$user) {
            $m->reply($e->replyContext, $this->notLinkedText($m));
            return;
        }

        // ไม่ระบุชื่อ → ปุ่มเลือกพอร์ต (normalized buttons — LINE=quickReply / TG=keyboard)
        if (count($parts) < 2) {
            $portfolios = $user->portfolios()->orderBy('name')->get();
            if ($portfolios->isEmpty()) {
                $m->reply($e->replyContext, "ยังไม่มีพอร์ต — สร้างได้ที่หน้าเว็บ");
                return;
            }
            $options = [];
            foreach ($portfolios->take(13) as $p) {
                $options[] = ['label' => $p->name, 'text' => '/portfolio ' . $p->name];
            }
            $m->reply($e->replyContext, [
                'type'    => 'buttons',
                'text'    => "📁 เลือกพอร์ตที่ต้องการดู:",
                'options' => $options,
            ]);
            return;
        }

        // ระบุชื่อ → หาพอร์ตของ user (ตรงตัวก่อน แล้ว partial)
        $name = trim(implode(' ', array_slice($parts, 1)));
        $portfolio = $user->portfolios()->where('name', $name)->first()
            ?? $user->portfolios()->where('name', 'like', '%' . $name . '%')->first();

        if (!$portfolio) {
            $m->reply($e->replyContext, "ไม่พบพอร์ต \"{$name}\"\nพิมพ์ /portfolio เพื่อดูรายการ");
            return;
        }

        // คำนวณ + ส่งกราฟใช้เวลา → typing + defer + reply
        $m->startTyping($e->chatId);
        $portfolioId = $portfolio->id;
        $ctx = $e->replyContext;
        defer(function () use ($m, $ctx, $portfolioId) {
            $portfolio = Portfolio::find($portfolioId);
            if (!$portfolio) {
                return;
            }
            $data = $this->portfolio->buildHoldings($portfolio);

            if (empty($data['holdings'])) {
                $m->reply($ctx, "💼 {$portfolio->name}\nพอร์ตนี้ยังว่าง — เพิ่มหุ้นที่หน้าเว็บก่อน");
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
            $m->reply($ctx, [
                ['type' => 'text',  'text' => trim($txt)],
                ['type' => 'image', 'url'  => $chartUrl],
            ]);
        });
    }

    /** URL กราฟ doughnut สัดส่วนพอร์ตผ่าน QuickChart (ฟรี, คืน PNG) */
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

    // ───────────────────────── /list ─────────────────────────

    private function cmdList(MessagingService $m, BotEvent $e): void
    {
        $user = $this->userFor($m, $e->chatId);
        if (!$user) {
            $m->reply($e->replyContext, $this->notLinkedText($m));
            return;
        }

        $symbols = $user->stocks()->orderBy('symbol')->pluck('symbol')->toArray();
        if (empty($symbols)) {
            $m->reply($e->replyContext, "คุณยังไม่ได้ติดตามหุ้นใด — เพิ่มได้ที่หน้าเว็บ");
            return;
        }
        $m->reply($e->replyContext, "📋 หุ้นที่คุณติดตาม:\n" . implode(', ', $symbols));
    }

    // ───────────────────────── /link ─────────────────────────

    private function cmdLink(MessagingService $m, BotEvent $e, array $parts): void
    {
        if (count($parts) < 2) {
            $m->reply($e->replyContext, "ใช้: /link รหัส6หลัก\nสร้างรหัสได้ที่หน้าโปรไฟล์ในเว็บ");
            return;
        }

        $code = strtoupper(trim($parts[1]));
        $user = User::where('messaging_link_code', $code)
            ->where('messaging_link_code_expires_at', '>', now())
            ->first();

        if (!$user) {
            $m->reply($e->replyContext, "รหัสไม่ถูกต้องหรือหมดอายุแล้ว ❌\nสร้างรหัสใหม่ที่หน้าโปรไฟล์");
            return;
        }

        // chat id นี้ถูกผูกกับบัญชีอื่น (provider เดียวกัน) อยู่แล้วหรือไม่
        $taken = User::where('messaging_chat_id', $e->chatId)
            ->where('messaging_provider', $m->provider())
            ->where('id', '!=', $user->id)->exists();
        if ($taken) {
            $m->reply($e->replyContext, $this->providerLabel($m) . " นี้ถูกผูกกับบัญชีอื่นอยู่แล้ว — ปลดการผูกที่บัญชีนั้นก่อน");
            return;
        }

        $user->forceFill([
            'messaging_chat_id'              => $e->chatId,
            'messaging_provider'             => $m->provider(),
            'messaging_link_code'            => null,
            'messaging_link_code_expires_at' => null,
        ])->save();

        $m->reply($e->replyContext, "✅ ผูกบัญชีสำเร็จ!\nคุณ {$user->name} จะได้รับแจ้งเตือนราคา + สรุปเช้าที่ " . $this->providerLabel($m) . " นี้");
    }

    // ───────────────────────── helpers ─────────────────────────

    /** user ที่ผูก chat id นี้กับ provider นี้ */
    private function userFor(MessagingService $m, string $chatId): ?User
    {
        return User::where('messaging_chat_id', $chatId)
            ->where('messaging_provider', $m->provider())
            ->first();
    }

    private function resolveOrImport(string $input): ?Stock
    {
        $sym = strtoupper(trim($input));

        if ($stock = $this->stockWithPrices($sym)) {
            return $stock;
        }
        if ($stock = $this->stockWithPrices($sym . '.BK')) {
            return $stock;
        }

        $this->importSymbol($sym);
        if ($stock = $this->stockWithPrices($sym)) {
            return $stock;
        }

        if (!str_ends_with($sym, '.BK')) {
            $this->importSymbol($sym . '.BK');
            if ($stock = $this->stockWithPrices($sym . '.BK')) {
                return $stock;
            }
        }

        return null;
    }

    private function stockWithPrices(string $symbol): ?Stock
    {
        return Stock::where('symbol', $symbol)->whereHas('prices')->first();
    }

    private function importSymbol(string $symbol): void
    {
        Artisan::call('app:fetch-stock-data', ['symbol' => $symbol, '--years' => 5]);
        Stock::where('symbol', $symbol)->whereDoesntHave('prices')->delete();
    }

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

    private function notLinkedText(MessagingService $m): string
    {
        return "🔗 ยังไม่ได้ผูกบัญชี " . $this->providerLabel($m) . " กับระบบ\n"
            . "ไปที่หน้าโปรไฟล์ในเว็บ → สร้างรหัสผูกบัญชี → พิมพ์ /link รหัส ที่นี่";
    }

    private function providerLabel(MessagingService $m): string
    {
        return $m->provider() === 'telegram' ? 'Telegram' : 'LINE';
    }

    private function helpText(): string
    {
        return "📌 คำสั่งที่ใช้ได้:\n"
            . "/ask SYMBOL — วิเคราะห์ด้วย AI\n"
            . "/plan SYMBOL เงิน/เดือน ปี — จำลอง DCA\n"
            . "/news SYMBOL — ข่าวล่าสุด\n"
            . "/portfolio — ดูพอร์ต + กราฟสัดส่วน\n"
            . "/list — ดูหุ้นที่คุณติดตาม\n"
            . "/link รหัส — ผูกบัญชีกับเว็บ\n\n"
            . "ตัวอย่าง:\n/ask NVDA\n/plan PTT 5000 10\n/portfolio";
    }

    /** ข้อความต้อนรับ — ส่งตอน user แอดบอทครั้งแรก (follow/start) */
    private function welcomeText(): string
    {
        return "🎉 ยินดีต้อนรับสู่ StockAI!\n"
            . "ผู้ช่วยวิเคราะห์หุ้นด้วย AI 🤖\n"
            . "━━━━━━━━━━━━━\n"
            . "📊 คำสั่งที่ใช้ได้:\n\n"
            . "🔹 /ask SYMBOL\n   วิเคราะห์หุ้นด้วย AI (Rating/ความเสี่ยง)\n   เช่น  /ask NVDA  หรือ  /ask PTT.BK\n\n"
            . "🔹 /plan SYMBOL เงิน/เดือน ปี\n   จำลอง DCA ย้อนหลังว่าได้กำไรเท่าไหร่\n   เช่น  /plan NVDA 5000 10\n\n"
            . "🔹 /news SYMBOL\n   ข่าวล่าสุดของหุ้น\n   เช่น  /news TSM\n\n"
            . "🔹 /list\n   หุ้นที่คุณติดตามทั้งหมด\n\n"
            . "🔹 /portfolio\n   สรุปพอร์ต + กราฟสัดส่วน\n\n"
            . "━━━━━━━━━━━━━\n"
            . "🔗 อยากใช้ /list /portfolio + รับแจ้งเตือนราคา/สรุปเช้า?\n"
            . "ผูกบัญชีก่อน: เข้าหน้าโปรไฟล์ในเว็บ → สร้างรหัส → พิมพ์  /link รหัส  ที่นี่\n\n"
            . "💡 พิมพ์ /help เพื่อดูคำสั่งอีกครั้ง";
    }
}
