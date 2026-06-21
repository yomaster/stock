<?php

namespace App\Console\Commands;

use App\Models\News;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Services\GeminiService;
use App\Services\LineService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('app:send-summary {--market=ALL : ตลาดที่จะสรุป TH|US|ALL} {--dry : แสดงผลเฉยๆ ไม่ส่ง LINE}')]
#[Description('สร้างสรุปก่อนตลาดเปิด (ราคา + ภาพรวม AI จากข่าว) แล้วส่งเข้า LINE')]
class SendSummary extends Command
{
    public function handle(GeminiService $gemini, LineService $line): int
    {
        $market = strtoupper($this->option('market'));
        $marketLabel = match ($market) {
            'TH' => 'ตลาดหุ้นไทย',
            'US' => 'ตลาดหุ้นสหรัฐ',
            default => 'ทุกตลาด',
        };

        // เลือกหุ้นตามตลาด (TH = .BK / US = ไม่มี .BK)
        $stocks = Stock::all()->filter(function ($s) use ($market) {
            $isThai = str_ends_with(strtoupper($s->symbol), '.BK');
            return match ($market) {
                'TH' => $isThai,
                'US' => !$isThai,
                default => true,
            };
        });

        if ($stocks->isEmpty()) {
            $this->warn("ไม่มีหุ้นในตลาด {$market}");
            return self::SUCCESS;
        }

        // ── ส่วนราคา + เปลี่ยนแปลงรายตัว ──
        $priceLines = [];
        $newsTitles = [];
        foreach ($stocks as $stock) {
            $prices = StockPrice::where('stock_id', $stock->id)
                ->orderBy('date', 'desc')->limit(2)->get();
            $latest = $prices->first();
            $prev   = $prices->get(1);
            if (!$latest) {
                continue;
            }

            $change = ($prev && $prev->close > 0)
                ? (($latest->close - $prev->close) / $prev->close) * 100
                : null;

            $arrow = $change === null ? '•' : ($change >= 0 ? '🟢▲' : '🔴▼');
            $chgStr = $change === null ? '' : ' ' . ($change >= 0 ? '+' : '') . number_format($change, 2) . '%';
            $priceLines[] = "{$arrow} {$stock->symbol}: " . number_format($latest->close, 2) . " {$stock->currency}{$chgStr}";

            // เก็บหัวข้อข่าวล่าสุด 2 วันไว้ให้ AI สรุปภาพรวม
            $recentNews = News::where('symbols', 'like', '%' . $stock->symbol . '%')
                ->where('published_at', '>=', Carbon::now()->subDays(2))
                ->orderBy('published_at', 'desc')->limit(3)->get();
            foreach ($recentNews as $n) {
                $newsTitles[] = '- ' . ($n->title_th ?? $n->title);
            }
        }

        // ── ภาพรวมจาก AI (1 call เดียว ประหยัด token) ──
        $overview = '';
        if (!empty($newsTitles)) {
            $newsBlock = implode("\n", array_slice(array_unique($newsTitles), 0, 15));
            $prompt = "คุณคือนักวิเคราะห์ตลาดหุ้น สรุปภาพรวม{$marketLabel}ก่อนเปิดตลาดวันนี้ "
                . "เป็นภาษาไทยสั้นๆ 2-3 ประโยค กระชับ อ่านเข้าใจง่ายสำหรับนักลงทุนมือใหม่ "
                . "อิงจากพาดหัวข่าวล่าสุดต่อไปนี้:\n{$newsBlock}\n\n"
                . "ตอบเฉพาะเนื้อหาสรุป ไม่ต้องมีหัวข้อหรือ markdown";
            $ai = $gemini->generateText($prompt, ['maxOutputTokens' => 512]);
            if ($ai) {
                $overview = trim($ai);
            }
        }

        // ── ประกอบข้อความ ──
        $date = Carbon::now()->format('d/m/Y');
        $msg  = "☀️ สรุปก่อน{$marketLabel}เปิด\n{$date}\n";
        $msg .= "━━━━━━━━━━━━━\n";
        $msg .= implode("\n", $priceLines);
        if ($overview) {
            $msg .= "\n\n💡 ภาพรวม:\n{$overview}";
        }
        $msg .= "\n\nพิมพ์ /ask SYMBOL เพื่อวิเคราะห์เจาะลึก";

        if ($this->option('dry')) {
            $this->line($msg);
            return self::SUCCESS;
        }

        $ok = $line->pushToDefault($msg);
        $this->info($ok ? "ส่งสรุป {$marketLabel} เข้า LINE แล้ว" : "ส่งไม่สำเร็จ (ตรวจ recipient_id / token)");

        return self::SUCCESS;
    }
}
