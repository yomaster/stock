<?php

namespace App\Console\Commands;

use App\Models\Stock;
use App\Models\StockPrice;
use App\Services\LineService;
use App\Services\SettingsService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

#[Signature('app:check-alerts {--dry : แสดงผลเฉยๆ ไม่ส่ง LINE}')]
#[Description('ตรวจราคา/วอลุ่มผิดปกติระหว่างวัน แล้วแจ้งเตือนด่วนเข้า LINE')]
class CheckAlerts extends Command
{
    public function handle(SettingsService $settings, LineService $line): int
    {
        $priceThreshold = (float) $settings->get('alert.price_threshold', 5);
        $volMultiplier  = (float) $settings->get('alert.volume_multiplier', 2.5);
        $today = Carbon::now()->toDateString();

        $stocks = Stock::all();
        $alertsSent = 0;

        foreach ($stocks as $stock) {
            try {
                $quote = $this->fetchQuote($stock->symbol);
                if (!$quote) {
                    continue;
                }

                $price = $quote['price'];
                $prevClose = $quote['prevClose'];
                $volume = $quote['volume'];

                if ($prevClose <= 0) {
                    continue;
                }

                $changePct = (($price - $prevClose) / $prevClose) * 100;

                // ── แจ้งเตือนราคา (ครั้งเดียวต่อวันต่อหุ้น) ──
                if (abs($changePct) >= $priceThreshold) {
                    $key = "alert_price:{$stock->symbol}:{$today}";
                    if (!Cache::has($key)) {
                        $dir = $changePct >= 0 ? '🟢 พุ่งขึ้น' : '🔴 ร่วงลง';
                        $msg = "🚨 แจ้งเตือนราคา {$stock->symbol}\n"
                            . "{$dir} " . number_format(abs($changePct), 2) . "% วันนี้\n"
                            . "ราคา: " . number_format($price, 2) . " {$stock->currency}"
                            . " (จาก " . number_format($prevClose, 2) . ")";
                        $this->dispatchAlert($line, $msg, $key);
                        $alertsSent++;
                    }
                }

                // ── แจ้งเตือน Volume ผิดปกติ ──
                $avgVol = $this->avgVolume($stock->id);
                if ($avgVol > 0 && $volume > 0 && $volume >= $avgVol * $volMultiplier) {
                    $key = "alert_vol:{$stock->symbol}:{$today}";
                    if (!Cache::has($key)) {
                        $times = round($volume / $avgVol, 1);
                        $msg = "📊 แจ้งเตือน Volume {$stock->symbol}\n"
                            . "วอลุ่มวันนี้สูงผิดปกติ {$times} เท่าของค่าเฉลี่ย\n"
                            . "ราคาปัจจุบัน: " . number_format($price, 2) . " {$stock->currency}"
                            . " (" . ($changePct >= 0 ? '+' : '') . number_format($changePct, 2) . "%)";
                        $this->dispatchAlert($line, $msg, $key);
                        $alertsSent++;
                    }
                }

            } catch (\Throwable $e) {
                $this->error("  {$stock->symbol}: " . $e->getMessage());
            }
        }

        $this->info("ตรวจเสร็จ — ส่งแจ้งเตือน {$alertsSent} รายการ");
        return self::SUCCESS;
    }

    private function dispatchAlert(LineService $line, string $msg, string $cacheKey): void
    {
        if ($this->option('dry')) {
            $this->line($msg);
            $this->line('---');
            return;
        }
        $line->pushToDefault($msg);
        // กันส่งซ้ำในวันเดียวกัน — หมดอายุสิ้นวัน
        Cache::put($cacheKey, true, Carbon::now()->endOfDay());
    }

    private function fetchQuote(string $symbol): ?array
    {
        $response = Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        ])->get("https://query1.finance.yahoo.com/v8/finance/chart/{$symbol}", [
            'interval' => '1d',
            'range'    => '1d',
        ]);

        if ($response->failed()) {
            return null;
        }

        $meta = $response->json('chart.result.0.meta');
        if (!$meta || !isset($meta['regularMarketPrice'])) {
            return null;
        }

        return [
            'price'     => (float) $meta['regularMarketPrice'],
            'prevClose' => (float) ($meta['chartPreviousClose'] ?? $meta['previousClose'] ?? 0),
            'volume'    => (int) ($meta['regularMarketVolume'] ?? 0),
        ];
    }

    /** ค่าเฉลี่ย volume 20 วันล่าสุดจาก DB */
    private function avgVolume(int $stockId): float
    {
        $vols = StockPrice::where('stock_id', $stockId)
            ->whereNotNull('volume')
            ->orderBy('date', 'desc')
            ->limit(20)
            ->pluck('volume');

        return $vols->isNotEmpty() ? (float) $vols->avg() : 0;
    }
}
