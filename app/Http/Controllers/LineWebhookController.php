<?php

namespace App\Http\Controllers;

use App\Jobs\AskStockJob;
use App\Models\News;
use App\Models\Stock;
use App\Services\InvestmentService;
use App\Services\LineService;
use App\Services\SettingsService;
use Illuminate\Http\Request;

class LineWebhookController extends Controller
{
    public function __construct(
        private LineService $line,
        private SettingsService $settings,
        private InvestmentService $investment,
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
            // เก็บ source id (user/group/room) ไว้ใช้ push + auto-set recipient
            $sourceId = $this->extractSourceId($event);

            // ตั้ง recipient อัตโนมัติครั้งแรก ถ้ายังไม่เคยตั้ง
            if ($sourceId && !$this->settings->isSet('line.recipient_id')) {
                $this->settings->set('line.recipient_id', $sourceId);
            }

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
            case '/list':
                $this->cmdList($replyToken);
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

    /** /ask SYMBOL — วิเคราะห์ด้วย AI (ทำใน background แล้ว push กลับ) */
    private function cmdAsk(array $parts, string $replyToken, ?string $sourceId): void
    {
        if (count($parts) < 2) {
            $this->line->reply($replyToken, "ใช้: /ask SYMBOL\nเช่น /ask NVDA หรือ /ask PTT");
            return;
        }

        $stock = $this->resolveStock($parts[1]);
        if (!$stock) {
            $this->line->reply($replyToken, $this->notFoundText($parts[1]));
            return;
        }

        if (!$sourceId) {
            $this->line->reply($replyToken, "ไม่สามารถระบุผู้รับผลวิเคราะห์ได้");
            return;
        }

        // แสดง loading animation (จุดเด้งๆ) ระหว่างรอ AI
        $this->line->startLoading($sourceId, 60);

        // ประมวลผล AI หลังส่ง 200 กลับ LINE แล้ว (defer) → ไม่ webhook timeout, ไม่ต้องพึ่ง queue/cron
        // AskStockJob::__construct(replyTo, symbol)
        $symbol = $stock->symbol;
        defer(fn () => AskStockJob::dispatchSync($sourceId, $symbol));
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

        $msg  = "📈 DCA {$stock->symbol} ย้อนหลัง {$years} ปี\n";
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

    /** /list — หุ้นทั้งหมดในระบบ */
    private function cmdList(string $replyToken): void
    {
        $symbols = Stock::orderBy('symbol')->pluck('symbol')->toArray();
        if (empty($symbols)) {
            $this->line->reply($replyToken, "ยังไม่มีหุ้นในระบบ");
            return;
        }
        $this->line->reply($replyToken, "📋 หุ้นในระบบ:\n" . implode(', ', $symbols));
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
            . "/list — ดูหุ้นทั้งหมด\n\n"
            . "ตัวอย่าง:\n/ask NVDA\n/plan PTT 5000 10\n/news AAPL";
    }
}
