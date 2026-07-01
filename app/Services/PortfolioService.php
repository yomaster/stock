<?php

namespace App\Services;

use App\Models\Portfolio;
use App\Models\Stock;
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

    /**
     * ราคาปัจจุบันของสินทรัพย์
     * - หุ้น/ETF: ราคาสดจาก Yahoo (fallback ราคาล่าสุดใน DB)
     * - กองทุน/ทองคำ: ใช้ราคาล่าสุดใน DB เท่านั้น (NAV / GTA)
     *   ⚠️ ห้ามยิง Yahoo เพราะ symbol อาจชนหุ้นจริง (เช่น "GOLD" = Barrick Gold ~$40)
     */
    private function currentPrice(Stock $stock): ?float
    {
        if (in_array($stock->asset_category, ['stock', 'etf'], true)) {
            $live = $this->livePrice($stock->symbol);
            if ($live !== null) {
                return $live;
            }
        }
        return optional(
            StockPrice::where('stock_id', $stock->id)->orderBy('date', 'desc')->first()
        )->close;
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

    /**
     * คำนวณพอร์ตแบบ Average Cost — รองรับทั้งซื้อ (buy) และขาย (sell)
     * คืน:
     *   positions[]    = สถานะสุทธิต่อหุ้น (หุ้นคงเหลือ/ต้นทุนเฉลี่ย/มูลค่า/กำไรยังไม่รับรู้ + สัดส่วน)
     *   transactions[] = ledger ทุกธุรกรรม (สำหรับ "รายการถือครอง") เรียงวันใหม่→เก่า
     *   totals         = มูลค่า/ต้นทุนคงเหลือ/กำไรยังไม่รับรู้ + กำไรที่รับรู้แล้ว
     */
    public function buildHoldings(Portfolio $portfolio): array
    {
        $rate  = $this->currentFx();
        $items = $portfolio->items()->with('stock')->get();

        // ── สะสมต่อหุ้น (buy/sell) ──
        $byStock = [];
        foreach ($items as $item) {
            $stock = $item->stock;
            if (!$stock) {
                continue;
            }
            $sid = $stock->id;
            if (!isset($byStock[$sid])) {
                $byStock[$sid] = [
                    'stock' => $stock, 'symbol' => $stock->symbol, 'name' => $stock->name,
                    'buy_shares' => 0, 'buy_cost_thb' => 0, 'sell_shares' => 0, 'sell_proceeds_thb' => 0,
                ];
            }
            // ใช้สกุลเงินจริงของสินทรัพย์ (กองทุน/หุ้นไทย = THB, US = USD)
            // เดิมเช็ค .BK suffix → กองทุน (เช่น K-GHEALTH) ไม่มี .BK เลยถูกคูณ FX ผิด
            $isUsd = strtoupper($stock->currency) === 'USD';
            $fx    = $item->purchase_date ? $this->historicalFx($item->purchase_date->toDateString()) : $rate; // FX วันธุรกรรม
            $thb   = $this->itemThb($item, $isUsd, $fx);

            if (($item->type ?? 'buy') === 'sell') {
                $byStock[$sid]['sell_shares']      += $item->shares;
                $byStock[$sid]['sell_proceeds_thb'] += $thb;
            } else {
                $byStock[$sid]['buy_shares']   += $item->shares;
                $byStock[$sid]['buy_cost_thb'] += $thb;
            }
        }

        // ── คำนวณ position สุทธิ + realized ──
        $positions = [];
        $totalValueThb = 0;
        $totalCostThb  = 0;
        $totalRealized = 0;
        $priceMap = [];

        foreach ($byStock as $sid => $b) {
            $stock   = $b['stock'];
            $avgCost = $b['buy_shares'] > 0 ? $b['buy_cost_thb'] / $b['buy_shares'] : 0; // THB ต่อหุ้น
            $netShares = $b['buy_shares'] - $b['sell_shares'];

            // กำไรที่รับรู้แล้ว = เงินที่ได้จากการขาย − ต้นทุนเฉลี่ยของหุ้นที่ขาย
            $totalRealized += $b['sell_proceeds_thb'] - ($b['sell_shares'] * $avgCost);

            if ($netShares <= 0.0000001) {
                continue; // ขายหมด — ไม่อยู่ในพอร์ต (realized ค้างใน total แล้ว)
            }

            $isUsd   = strtoupper($stock->currency) === 'USD';
            $current = $this->currentPrice($stock) ?? 0;
            $priceMap[$sid] = $current;

            $valueThb = $netShares * $current * ($isUsd ? $rate : 1);
            $costThb  = $avgCost * $netShares;

            $totalValueThb += $valueThb;
            $totalCostThb  += $costThb;

            $positions[] = [
                'symbol'            => $stock->symbol,
                'name'              => $stock->name,
                'asset_category'    => $stock->asset_category,
                'currency'          => $stock->currency,
                'net_shares'        => $netShares,
                'avg_cost_thb'      => $avgCost,
                'current_price'     => $current,
                'value_thb'         => $valueThb,
                'cost_thb'          => $costThb,
                'unrealized_pl_thb' => $valueThb - $costThb,
                'unrealized_pl_pct' => $costThb > 0 ? (($valueThb - $costThb) / $costThb) * 100 : 0,
            ];
        }

        foreach ($positions as &$p) {
            $p['allocation'] = $totalValueThb > 0 ? ($p['value_thb'] / $totalValueThb) * 100 : 0;
        }
        unset($p);
        usort($positions, fn ($a, $b) => $b['value_thb'] <=> $a['value_thb']);

        // ── ledger: ทุกธุรกรรม (สำหรับ "รายการถือครอง") ──
        $transactions = [];
        foreach ($items as $item) {
            $stock = $item->stock;
            if (!$stock) {
                continue;
            }
            $current = $priceMap[$stock->id]
                ?? $this->currentPrice($stock)
                ?? $item->purchase_price;

            $transactions[] = [
                'id'                => $item->id,
                'type'              => $item->type ?? 'buy',
                'symbol'            => $stock->symbol,
                'name'              => $stock->name,
                'currency'          => $stock->currency,
                'shares'            => $item->shares,
                'purchase_price'    => $item->purchase_price,
                'invested_amount'   => $item->invested_amount,
                'invested_currency' => $item->invested_currency,
                'fx_rate'           => $item->fx_rate,
                'current_price'     => $current,
                'note'              => $item->note, // ที่มา (สับเปลี่ยน) ถ้ามี
                'purchase_date'     => $item->purchase_date?->format('d/m/Y'),
                'purchase_date_raw' => $item->purchase_date?->format('Y-m-d'),
                // วันเวลาแสดงผล: ถ้ามี executed_at (เวลาจริง) โชว์เวลาด้วย
                'datetime_label'    => $item->executed_at
                    ? $item->executed_at->format('d/m/Y H:i:s')
                    : $item->purchase_date?->format('d/m/Y'),
                'time_raw'          => $item->executed_at?->format('H:i:s'),
                // key สำหรับเรียง: ใช้ executed_at (มีเวลา) ถ้าไม่มีใช้วันที่ 00:00
                'sort_key'          => $item->executed_at?->format('Y-m-d H:i:s')
                    ?? (($item->purchase_date?->format('Y-m-d') ?? '0000-00-00') . ' 00:00:00'),
            ];
        }
        // เรียงตามวันเวลา ใหม่→เก่า (รวมเวลา — วันเดียวกันคนละเวลาเรียงถูก)
        usort($transactions, fn ($a, $b) => $b['sort_key'] <=> $a['sort_key']);

        return [
            'positions'            => $positions,
            'transactions'         => $transactions,
            'total_value_thb'      => $totalValueThb,
            'total_cost_thb'       => $totalCostThb,
            'total_unrealized_pl'  => $totalValueThb - $totalCostThb,
            'total_unrealized_pct' => $totalCostThb > 0 ? (($totalValueThb - $totalCostThb) / $totalCostThb) * 100 : 0,
            'total_realized_pl'    => $totalRealized,
        ];
    }

    /**
     * รวมทุกพอร์ตของ user (cross-portfolio overview)
     * คืน:
     *   portfolios[]  = สรุปรายพอร์ต (มูลค่า/ต้นทุน/กำไร + สัดส่วนของมูลค่ารวม)
     *   totals        = มูลค่า/ต้นทุน/กำไรยังไม่รับรู้ + กำไรที่รับรู้แล้ว รวมทุกพอร์ต
     *   by_category   = แยกตามชนิดสินทรัพย์ (value/cost/pnl/pct/allocation)
     *   top_holdings  = รวมสินทรัพย์เดียวกันข้ามพอร์ต เรียงมูลค่ามาก→น้อย
     *
     * @param \Illuminate\Support\Collection|iterable $portfolios พอร์ตของ user (โหลด items มาแล้วยิ่งดี)
     */
    public function crossPortfolioOverview($portfolios): array
    {
        $rows       = [];
        $byCategory = [];
        $bySymbol   = [];
        $sumValue = 0;
        $sumCost  = 0;
        $sumRealized = 0;

        foreach ($portfolios as $pf) {
            $h = $this->buildHoldings($pf);

            $rows[] = [
                'id'                => $pf->id,
                'name'              => $pf->name,
                'category'          => $pf->category,
                'value_thb'         => $h['total_value_thb'],
                'cost_thb'          => $h['total_cost_thb'],
                'unrealized_pl_thb' => $h['total_unrealized_pl'],
                'unrealized_pl_pct' => $h['total_unrealized_pct'],
                'realized_pl_thb'   => $h['total_realized_pl'],
                'positions_count'   => count($h['positions']),
            ];

            $sumValue    += $h['total_value_thb'];
            $sumCost     += $h['total_cost_thb'];
            $sumRealized += $h['total_realized_pl'];

            foreach ($h['positions'] as $pos) {
                // รวมตามชนิด
                $cat = $pos['asset_category'] ?? 'stock';
                $byCategory[$cat] ??= ['value' => 0, 'cost' => 0, 'count' => 0];
                $byCategory[$cat]['value'] += $pos['value_thb'];
                $byCategory[$cat]['cost']  += $pos['cost_thb'];
                $byCategory[$cat]['count']++;

                // รวมตาม symbol (สินทรัพย์เดียวกันข้ามพอร์ต)
                $sym = $pos['symbol'];
                $bySymbol[$sym] ??= [
                    'symbol' => $sym, 'name' => $pos['name'], 'asset_category' => $cat,
                    'value_thb' => 0, 'cost_thb' => 0,
                ];
                $bySymbol[$sym]['value_thb'] += $pos['value_thb'];
                $bySymbol[$sym]['cost_thb']  += $pos['cost_thb'];
            }
        }

        // สัดส่วนของมูลค่ารวม + กำไรต่อพอร์ต
        foreach ($rows as &$r) {
            $r['allocation'] = $sumValue > 0 ? ($r['value_thb'] / $sumValue) * 100 : 0;
        }
        unset($r);
        usort($rows, fn ($a, $b) => $b['value_thb'] <=> $a['value_thb']);

        foreach ($byCategory as &$b) {
            $b['pnl']        = $b['value'] - $b['cost'];
            $b['pnl_pct']    = $b['cost'] > 0 ? ($b['pnl'] / $b['cost']) * 100 : 0;
            $b['allocation'] = $sumValue > 0 ? ($b['value'] / $sumValue) * 100 : 0;
        }
        unset($b);
        uasort($byCategory, fn ($a, $b) => $b['value'] <=> $a['value']);

        $holdings = array_values($bySymbol);
        foreach ($holdings as &$hh) {
            $hh['unrealized_pl_thb'] = $hh['value_thb'] - $hh['cost_thb'];
            $hh['unrealized_pl_pct'] = $hh['cost_thb'] > 0 ? (($hh['value_thb'] - $hh['cost_thb']) / $hh['cost_thb']) * 100 : 0;
            $hh['allocation']        = $sumValue > 0 ? ($hh['value_thb'] / $sumValue) * 100 : 0;
        }
        unset($hh);
        usort($holdings, fn ($a, $b) => $b['value_thb'] <=> $a['value_thb']);

        return [
            'portfolios'   => $rows,
            'by_category'  => $byCategory,
            'top_holdings' => $holdings,
            'totals'       => [
                'value_thb'         => $sumValue,
                'cost_thb'          => $sumCost,
                'unrealized_pl_thb' => $sumValue - $sumCost,
                'unrealized_pl_pct' => $sumCost > 0 ? (($sumValue - $sumCost) / $sumCost) * 100 : 0,
                'realized_pl_thb'   => $sumRealized,
                'portfolios_count'  => count($rows),
            ],
        ];
    }

    /**
     * แปลงมูลค่าธุรกรรม (เงินซื้อ/ขาย) เป็น THB
     * - ใช้ fx_rate ที่ user กรอกเอง (เรทจริงจากโบรก) ก่อน — ถ้าไม่มีใช้ FX ตลาดวันนั้น
     * - รายการสกุล THB ไม่ต้องแปลง
     */
    private function itemThb($item, bool $isUsd, float $fx): float
    {
        $useFx = $item->fx_rate ?: $fx; // override > เรทตลาด

        if ($item->invested_amount) {
            return $item->invested_currency === 'USD' ? $item->invested_amount * $useFx : $item->invested_amount;
        }
        $native = $item->purchase_price * $item->shares;
        return $isUsd ? $native * $useFx : $native;
    }
}
