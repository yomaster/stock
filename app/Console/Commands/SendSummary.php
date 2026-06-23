<?php

namespace App\Console\Commands;

use App\Models\News;
use App\Models\StockPrice;
use App\Models\User;
use App\Services\GeminiService;
use App\Services\LineService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('app:send-summary {--market=ALL : ตลาดที่จะสรุป TH|US|ALL} {--dry : แสดงผลเฉยๆ ไม่ส่ง LINE}')]
#[Description('สร้างสรุปก่อนตลาดเปิด (ราคา + ภาพรวม AI จากข่าว) แล้วส่งเข้า LINE รายคน')]
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

        // ผู้ใช้ที่เปิดรับสรุป + ผูก LINE แล้ว
        $users = User::where('summary_enabled', true)->whereNotNull('line_user_id')->get();
        if ($users->isEmpty()) {
            $this->warn('ไม่มีผู้ใช้ที่เปิดรับสรุป + ผูก LINE');
            return self::SUCCESS;
        }

        $sent = 0;
        foreach ($users as $user) {
            // หุ้นที่ user คนนี้ติดตาม กรองตามตลาด
            $stocks = $user->stocks()->get()->filter(function ($s) use ($market) {
                $isThai = str_ends_with(strtoupper($s->symbol), '.BK');
                return match ($market) {
                    'TH' => $isThai,
                    'US' => !$isThai,
                    default => true,
                };
            });

            if ($stocks->isEmpty()) {
                continue; // ไม่มีหุ้นในตลาดนี้ → ข้าม
            }

            [$priceLines, $newsTitles] = $this->collectLines($stocks);

            // ── ภาพรวมจาก AI (1 call ต่อ user; summary model = Flash Lite ประหยัด token) ──
            $overview = '';
            if (!empty($newsTitles)) {
                $newsBlock = implode("\n", array_slice(array_unique($newsTitles), 0, 15));
                $prompt = "คุณคือนักวิเคราะห์ตลาดหุ้น สรุปภาพรวม{$marketLabel}ก่อนเปิดตลาดวันนี้ "
                    . "เป็นภาษาไทยสั้นๆ 2-3 ประโยค กระชับ อ่านเข้าใจง่ายสำหรับนักลงทุนมือใหม่ "
                    . "อิงจากพาดหัวข่าวล่าสุดต่อไปนี้:\n{$newsBlock}\n\n"
                    . "ตอบเฉพาะเนื้อหาสรุป ไม่ต้องมีหัวข้อหรือ markdown";
                $ai = $gemini->useSummaryModel()->generateText($prompt, ['maxOutputTokens' => 512]);
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
                $this->line("===== [{$user->email}] =====");
                $this->line($msg);
                continue;
            }

            if ($line->pushToUser($user, $msg)) {
                $sent++;
            }
        }

        $this->info($this->option('dry') ? 'แสดงตัวอย่างเสร็จ (dry)' : "ส่งสรุป {$marketLabel} แล้ว {$sent} คน");
        return self::SUCCESS;
    }

    /**
     * สร้างบรรทัดราคา + หัวข้อข่าว จาก collection ของหุ้น
     * @return array{0: string[], 1: string[]} [priceLines, newsTitles]
     */
    private function collectLines($stocks): array
    {
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

            $arrow  = $change === null ? '•' : ($change >= 0 ? '🟢▲' : '🔴▼');
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

        return [$priceLines, $newsTitles];
    }
}
