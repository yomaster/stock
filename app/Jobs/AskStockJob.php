<?php

namespace App\Jobs;

use App\Models\Stock;
use App\Services\InvestmentService;
use App\Services\LineService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class AskStockJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 120;

    public function __construct(
        public string $replyToken, // reply token (ฟรี ไม่หักโควตา) — ใช้ภายในอายุ ~1 นาที
        public string $symbol      // symbol จริงใน DB (resolve มาแล้ว)
    ) {}

    public function handle(InvestmentService $investment, LineService $line): void
    {
        $stock = Stock::where('symbol', $this->symbol)->first();
        if (!$stock) {
            $line->reply($this->replyToken, "ไม่พบหุ้น {$this->symbol} ในระบบ");
            return;
        }

        // ใช้ค่ามาตรฐานเพื่อให้ AI ประเมิน CAGR + สรุป (ลงทุน 0 + DCA หมื่น/เดือน 10 ปี)
        $result = $investment->projectFutureAI($stock->symbol, 0, 10000, 10);

        if (!$result['success']) {
            $line->reply($this->replyToken, "วิเคราะห์ {$stock->symbol} ไม่สำเร็จ: " . ($result['error'] ?? 'ลองใหม่อีกครั้ง'));
            return;
        }

        $p = $result['projections'];
        $msg  = "📊 บทวิเคราะห์ {$stock->symbol}\n";
        $msg .= "{$stock->name}\n";
        $msg .= "━━━━━━━━━━━━━\n";
        $msg .= "ความเสี่ยง: {$result['risk_score']}/10\n\n";
        $msg .= "🔮 คาดการณ์ CAGR ต่อปี:\n";
        $msg .= "🚀 Bull: " . number_format($p['bull']['cagr'], 1) . "%\n";
        $msg .= "📊 Base: " . number_format($p['base']['cagr'], 1) . "%\n";
        $msg .= "🐻 Bear: " . number_format($p['bear']['cagr'], 1) . "%\n\n";
        $msg .= "💡 " . $result['summary'];

        $line->reply($this->replyToken, $msg);
    }
}
