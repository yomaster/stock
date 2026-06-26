<?php

namespace App\Services;

use App\Models\Portfolio;
use App\Models\StockPrice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * รวม logic คำนวณพอร์ต (ใช้ร่วมหน้าเว็บ + LINE bot)
 * ราคา/FX ดึงสดจาก Yahoo + cache
 */
class PortfolioService
{
    public function __construct(private SettingsService $settings) {}

    /** ค่า fallback เมื่อดึงเรทสดไม่ได้ */
    public function exchangeRate(): float
    {
        return (float) $this->settings->get('general.default_exchange_rate', 33);
    }

    /** ราคาหุ้นสด (near real-time) จาก Yahoo regularMarketPrice — cache 3 นาที */
    public function livePrice(string $symbol): ?float
    {
        return Cache::remember("live_price:{$symbol}", now()->addMinutes(3), function () use ($symbol) {
            try {
                $resp = Http::withHeaders(['User-Agent' => 'Mozilla/5.0'])
                    ->get("https://query1.finance.yahoo.com/v8/finance/chart/{$symbol}", [
                        'interval' => '1d', 'range' => '1d',
                    ]);
                $price = $resp->json('chart.result.0.meta.regularMarketPrice');
                return $price ? (float) $price : null;
            } catch (\Throwable $e) {
                return null;
            }
        });
    }

    /** อัตราแลกเปลี่ยน USD→THB สด (วันนี้) — cache 6 ชม. */
    public function currentFx(): float
    {
        return Cache::remember('fx_usdthb_current', now()->addHours(6), function () {
            try {
                $resp = Http::withHeaders(['User-Agent' => 'Mozilla/5.0'])
                    ->get('https://query1.finance.yahoo.com/v8/finance/chart/THB=X', [
                        'interval' => '1d', 'range' => '1d',
                    ]);
                $price = $resp->json('chart.result.0.meta.regularMarketPrice');
                if ($price) {
                    return (float) $price;
                }
            } catch (\Throwable $e) {
                // ตกไป fallback
            }
            return $this->exchangeRate();
        });
    }

    /** ราคาปิด (สกุลหุ้น) ณ วันที่ หรือวันทำการก่อนหน้าที่ใกล้ที่สุด */
    public function historicalPrice(int $stockId, string $date): ?float
    {
        $row = StockPrice::where('stock_id', $stockId)
            ->where('date', '<=', $date)
            ->orderBy('date', 'desc')
            ->first(['close']);
        return $row ? (float) $row->close : null;
    }

    /** แปลงเงินลงทุนเป็นสกุลของหุ้น (ใช้ FX ย้อนหลังถ้าข้ามสกุล) */
    public function toNativeAmount(float $amount, string $paidCurrency, string $stockCurrency, string $date): float
    {
        if ($paidCurrency === $stockCurrency) {
            return $amount;
        }
        $fx = $this->historicalFx($date); // THB ต่อ 1 USD
        if ($paidCurrency === 'THB' && $stockCurrency === 'USD') {
            return $amount / $fx;
        }
        if ($paidCurrency === 'USD' && $stockCurrency === 'THB') {
            return $amount * $fx;
        }
        return $amount;
    }

    /** อัตราแลกเปลี่ยน USD→THB ย้อนหลัง (THB=X) ณ วันที่ — cache + fallback */
    public function historicalFx(string $date): float
    {
        return Cache::remember("fx_usdthb:{$date}", now()->addDays(30), function () use ($date) {
            try {
                $ts = Carbon::parse($date);
                $resp = Http::withHeaders(['User-Agent' => 'Mozilla/5.0'])
                    ->get('https://query1.finance.yahoo.com/v8/finance/chart/THB=X', [
                        'period1'  => $ts->copy()->subDays(7)->timestamp,
                        'period2'  => $ts->copy()->addDay()->timestamp,
                        'interval' => '1d',
                    ]);
                $result = $resp->json('chart.result.0');
                $closes = $result['indicators']['quote'][0]['close'] ?? [];
                $closes = array_values(array_filter($closes, fn ($c) => $c !== null));
                if (!empty($closes)) {
                    return (float) end($closes);
                }
            } catch (\Throwable $e) {
                // ตกไป fallback
            }
            return $this->exchangeRate();
        });
    }

    /** รวม holdings ตาม symbol (กราฟสัดส่วน + ตารางสรุปรายหุ้น) — รวมทุก lot ของหุ้นเดียวกัน */
    public function groupBySymbol(array $holdings, float $totalValueThb): array
    {
        $grouped = [];
        foreach ($holdings as $h) {
            $sym = $h['symbol'];
            if (!isset($grouped[$sym])) {
                $grouped[$sym] = ['symbol' => $sym, 'name' => $h['name'], 'value_thb' => 0, 'cost_thb' => 0, 'pl_value_thb' => 0];
            }
            $grouped[$sym]['value_thb']    += $h['value_thb'];
            $grouped[$sym]['cost_thb']     += $h['cost_thb'];
            $grouped[$sym]['pl_value_thb'] += $h['pl_value_thb'];
        }
        foreach ($grouped as &$g) {
            $g['allocation'] = $totalValueThb > 0 ? ($g['value_thb'] / $totalValueThb) * 100 : 0;
            $g['pl_percent'] = $g['cost_thb'] > 0 ? ($g['pl_value_thb'] / $g['cost_thb']) * 100 : 0;
        }
        unset($g);

        // เรียงตามมูลค่า (= สัดส่วน) มาก→น้อย
        usort($grouped, fn ($a, $b) => $b['value_thb'] <=> $a['value_thb']);
        return array_values($grouped);
    }

    /**
     * คำนวณ holdings: มูลค่าปัจจุบัน, ทุน, กำไร/ขาดทุน, สัดส่วน
     */
    public function buildHoldings(Portfolio $portfolio): array
    {
        $rate  = $this->currentFx();
        $items = $portfolio->items()->with('stock')->get();

        $holdings = [];
        $totalValueThb = 0;
        $totalCostThb  = 0;

        foreach ($items as $item) {
            $stock = $item->stock;
            if (!$stock) {
                continue;
            }

            $latest = StockPrice::where('stock_id', $stock->id)
                ->orderBy('date', 'desc')->first();
            $currentPrice = $this->livePrice($stock->symbol)
                ?? $latest?->close
                ?? $item->purchase_price;

            $value = $currentPrice * $item->shares;
            $isUsd = !str_ends_with(strtoupper($stock->symbol), '.BK');
            $valueThb = $isUsd ? $value * $rate : $value; // มูลค่าปัจจุบัน = FX วันนี้

            // ต้นทุน: ใช้ FX ตามวันที่ซื้อ (historical) — สะท้อนเงินที่จ่ายจริงตอนนั้น
            $buyFx = $item->purchase_date ? $this->historicalFx($item->purchase_date->toDateString()) : $rate;
            if ($item->invested_amount) {
                $investedThb = $item->invested_currency === 'USD'
                    ? $item->invested_amount * $buyFx
                    : $item->invested_amount; // จ่ายเป็น THB → ตรงๆ ไม่ต้องแปลง
            } else {
                $cost = $item->purchase_price * $item->shares;
                $investedThb = $isUsd ? $cost * $buyFx : $cost;
            }

            $totalValueThb += $valueThb;
            $totalCostThb  += $investedThb;

            $holdings[] = [
                'id'                => $item->id,
                'symbol'            => $stock->symbol,
                'name'              => $stock->name,
                'currency'          => $stock->currency,
                'shares'            => $item->shares,
                'purchase_price'    => $item->purchase_price,
                'invested_amount'   => $item->invested_amount,
                'invested_currency' => $item->invested_currency,
                'purchase_date'     => $item->purchase_date?->format('d/m/Y'),
                'purchase_date_raw' => $item->purchase_date?->format('Y-m-d'),
                'current_price'     => $currentPrice,
                'value'             => $value,
                'value_thb'         => $valueThb,
                'cost_thb'          => $investedThb,
                'pl_value_thb'      => $valueThb - $investedThb,
                'pl_percent'        => $investedThb > 0 ? (($valueThb - $investedThb) / $investedThb) * 100 : 0,
            ];
        }

        foreach ($holdings as &$h) {
            $h['allocation'] = $totalValueThb > 0 ? ($h['value_thb'] / $totalValueThb) * 100 : 0;
        }
        unset($h);

        // รายการถือครอง: เรียงตามวันที่ลงทุน ใหม่→เก่า (ตัวที่ไม่มีวันที่ไปท้ายสุด)
        usort($holdings, fn ($a, $b) => ($b['purchase_date_raw'] ?? '') <=> ($a['purchase_date_raw'] ?? ''));

        return [
            'holdings'         => $holdings,
            'total_value_thb'  => $totalValueThb,
            'total_cost_thb'   => $totalCostThb,
            'total_pl_thb'     => $totalValueThb - $totalCostThb,
            'total_pl_percent' => $totalCostThb > 0 ? (($totalValueThb - $totalCostThb) / $totalCostThb) * 100 : 0,
        ];
    }
}
