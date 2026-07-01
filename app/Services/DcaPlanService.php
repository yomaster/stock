<?php

namespace App\Services;

use App\Models\Portfolio;
use App\Models\Stock;
use App\Models\StockPrice;
use Illuminate\Support\Carbon;

/**
 * DcaPlanService (Phase 2) — คำนวณแผน DCA ด้วย "สูตร" ล้วน (ไม่ให้ AI คำนวณเลข)
 *
 * แนวคิด:
 *  - ดึง "สินทรัพย์ในพอร์ต" ปัจจุบัน → ใช้มูลค่าตลาดล่าสุด (THB) เป็น "ค่าตั้งต้น" รายตัว
 *  - ผู้ใช้กำหนดยอด DCA "แยกรายสินทรัพย์" + ความถี่ (รวมแบบเลือกวันที่ของเดือนเอง) + วันเริ่ม
 *  - ผลตอบแทนคาดการณ์รายตัวใช้ CAGR ย้อนหลัง (annualized จากข้อมูลทั้งหมดใน DB)
 *  - ทบต้นด้วยสูตร future value (ค่าตั้งต้น + เงิน DCA ทบต้นตามจำนวนงวด/ปี)
 *  - กำไร = มูลค่าอนาคต − (ค่าตั้งต้น + เงิน DCA ที่ใส่ทั้งหมด)  ← วัดการเติบโต "จากวันเริ่มแผน"
 *
 * ⚠️ ประมาณการ ไม่ใช่การรับประกัน — CAGR อดีตไม่การันตีอนาคต (โชว์ disclaimer ใน UI)
 */
class DcaPlanService
{
    public function __construct(private PortfolioService $portfolio) {}

    /** label ความถี่ภาษาไทย */
    public const FREQ_LABELS = [
        'daily'   => 'ทุกวัน',
        'weekly'  => 'ทุกสัปดาห์',
        'monthly' => 'ทุกเดือน',
        'custom'  => 'วันที่กำหนดเอง',
        'once'    => 'ครั้งเดียว',
    ];

    // กรอบ CAGR "อัตโนมัติ" (จากราคาย้อนหลัง) — cap ให้สมจริงสำหรับพยากรณ์ระยะยาว
    // เหตุผล: ผลตอบแทนช่วงบูม (เช่น 32-40%/ปี) ไม่ยั่งยืน ทบต้น 20 ปีแล้วเลขหลุดโลก
    // ผู้ใช้ "ปรับ CAGR เองรายตัว" ได้ (ดู MANUAL_*) ถ้าเชื่อว่าตัวไหนโตได้มากกว่า cap
    public const CAGR_MIN = -0.10; // -10%/ปี
    public const CAGR_MAX = 0.12;  // +12%/ปี (ใกล้เคียงผลตอบแทนหุ้น/ETF ระยะยาว)
    // กรอบค่าที่ผู้ใช้กรอกเอง — กว้างกว่า auto แต่ยังกันค่าเพ้อฝัน
    private const MANUAL_MIN = -0.50;
    private const MANUAL_MAX = 0.50;
    private const DEFAULT_CAGR = 0.05; // 5% เมื่อหาค่า CAGR ไม่ได้เลยทั้งพอร์ต

    /**
     * สินทรัพย์ในพอร์ต (สำหรับฟอร์ม) — symbol/ชื่อ/มูลค่าตั้งต้น/CAGR
     * - cagr_pct      = ค่าที่ใช้ตั้งต้น (cap แล้ว) → prefill ช่องให้ผู้ใช้ปรับ
     * - cagr_hist_pct = CAGR อดีตจริง (ยังไม่ cap) → โชว์เป็น hint ให้รู้ว่าของจริงเท่าไหร่
     */
    public function portfolioAssets(Portfolio $portfolio): array
    {
        $holdings  = $this->portfolio->buildHoldings($portfolio);
        $positions = $holdings['positions'];
        $stockMap  = Stock::whereIn('symbol', array_column($positions, 'symbol'))->get()->keyBy('symbol');

        $rows = [];
        foreach ($positions as $p) {
            $stock = $stockMap->get($p['symbol']);
            $c = $stock ? $this->cagr($stock) : null;
            $rows[] = [
                'symbol'         => $p['symbol'],
                'name'           => $p['name'],
                'asset_category' => $p['asset_category'],
                'value_thb'      => round($p['value_thb'], 2),
                'cagr_pct'       => $c !== null ? round($this->clampCagr($c) * 100, 2) : null,
                'cagr_hist_pct'  => $c !== null ? round($c * 100, 2) : null,
            ];
        }
        return $rows;
    }

    /**
     * คำนวณ projection ทั้งแผน
     *
     * @param array       $assetDca map symbol => ยอด DCA ต่อครั้ง (THB)
     * @param array       $assetCagr map symbol => CAGR คาดหวัง/ปี (%) ที่ผู้ใช้ปรับเอง (ว่าง = ใช้ auto cap)
     * @param string      $frequency daily|weekly|monthly|custom|once
     * @param array|null  $freqDays วันที่ของเดือน (เฉพาะ custom) เช่น [4,20]
     * @param int         $years จำนวนปี
     * @param string|null $startDate วันเริ่ม (Y-m-d) — ใช้แสดงผล/timeline
     * @param array       $excluded list ของ symbol ที่เอาออกจากแผน (ไม่นำมาคำนวณ)
     * @return array{assets: array, totals: array, meta: array}
     */
    public function project(Portfolio $portfolio, array $assetDca, array $assetCagr, string $frequency, ?array $freqDays, int $years, ?string $startDate = null, array $excluded = []): array
    {
        $holdings  = $this->portfolio->buildHoldings($portfolio);
        // ข้ามสินทรัพย์ที่ผู้ใช้เอาออกจากแผน
        $positions = array_values(array_filter(
            $holdings['positions'],
            fn ($p) => !in_array($p['symbol'], $excluded, true)
        ));

        $ppy    = $this->periodsPerYear($frequency, $freqDays);
        $isOnce = $frequency === 'once';

        // CAGR อัตโนมัติ (cap แล้ว) + ค่าเฉลี่ยถ่วงน้ำหนักไว้ fallback (เมื่อข้อมูลตัวนั้นสั้นเกินไป)
        $stockMap  = Stock::whereIn('symbol', array_column($positions, 'symbol'))->get()->keyBy('symbol');
        $cagrAuto  = []; // capped historical หรือ null
        $weightSum = 0;
        $cagrWeighted = 0;
        foreach ($positions as $p) {
            $stock = $stockMap->get($p['symbol']);
            $c = $stock ? $this->cagr($stock) : null;
            $capped = $c !== null ? $this->clampCagr($c) : null;
            $cagrAuto[$p['symbol']] = $capped;
            if ($capped !== null) {
                $cagrWeighted += $capped * $p['value_thb'];
                $weightSum    += $p['value_thb'];
            }
        }
        $fallbackCagr = $weightSum > 0 ? $cagrWeighted / $weightSum : self::DEFAULT_CAGR;

        $assets = [];
        $sumCurrent = 0;
        $sumFuture = 0;
        $sumInvested = 0;
        $sumContrib = 0;
        foreach ($positions as $p) {
            $sym  = $p['symbol'];
            $dca  = max(0, (float) ($assetDca[$sym] ?? 0));
            $init = $p['value_thb']; // ค่าตั้งต้น = มูลค่าตลาดปัจจุบัน

            // ลำดับความสำคัญของ CAGR: ผู้ใช้กรอกเอง > auto (cap) > ค่าเฉลี่ยพอร์ต (fallback)
            $manual = (isset($assetCagr[$sym]) && $assetCagr[$sym] !== '' && is_numeric($assetCagr[$sym]))
                ? $this->clampManual((float) $assetCagr[$sym] / 100)
                : null;
            $estimated = $manual === null && $cagrAuto[$sym] === null; // ใช้ fallback
            $cagr = $manual ?? $cagrAuto[$sym] ?? $fallbackCagr;
            $custom = $manual !== null;

            if ($isOnce) {
                // ลงเพิ่มก้อนเดียวตอนเริ่ม แล้วทบต้น
                $contribThb = $dca;
                $future     = ($init + $dca) * pow(1 + $cagr, $years);
            } else {
                $contribThb = $dca * $ppy * $years;
                $future     = $this->futureValue($init, $dca, $cagr, $years, $ppy);
            }
            $invested = $init + $contribThb; // ทุนรวม = ค่าตั้งต้น + เงิน DCA ทั้งหมด
            $profit   = $future - $invested;

            $assets[] = [
                'symbol'            => $sym,
                'name'              => $p['name'],
                'asset_category'    => $p['asset_category'],
                'dca_amount'        => round($dca, 2),
                'cagr_pct'          => round($cagr * 100, 2),
                'cagr_estimated'    => $estimated,
                'cagr_custom'       => $custom,
                'start_value_thb'   => round($init, 2),
                'contrib_thb'       => round($contribThb, 2),
                'invested_thb'      => round($invested, 2),
                'future_value_thb'  => round($future, 2),
                'profit_thb'        => round($profit, 2),
                'profit_pct'        => $invested > 0 ? round($profit / $invested * 100, 2) : 0,
            ];

            $sumCurrent  += $init;
            $sumFuture   += $future;
            $sumInvested += $invested;
            $sumContrib  += $contribThb;
        }

        usort($assets, fn ($a, $b) => $b['future_value_thb'] <=> $a['future_value_thb']);

        $totals = [
            'start_value_thb'  => round($sumCurrent, 2),
            'contrib_thb'      => round($sumContrib, 2),
            'invested_thb'     => round($sumInvested, 2),
            'future_value_thb' => round($sumFuture, 2),
            'profit_thb'       => round($sumFuture - $sumInvested, 2),
            'profit_pct'       => $sumInvested > 0 ? round(($sumFuture - $sumInvested) / $sumInvested * 100, 2) : 0,
        ];

        $endDate = $startDate ? Carbon::parse($startDate)->copy()->addYears($years) : null;

        return [
            'assets' => $assets,
            'totals' => $totals,
            'meta'   => [
                'generated_at'    => Carbon::now()->toDateTimeString(),
                'frequency'       => $frequency,
                'frequency_label' => $this->freqLabel($frequency, $freqDays),
                'periods_per_year' => $ppy,
                'years'           => $years,
                'start_date'      => $startDate,
                'end_date'        => $endDate?->toDateString(),
                'has_positions'   => !empty($assets),
            ],
        ];
    }

    /** จำนวนงวดต่อปีตามความถี่ ('once' = 0 คือก้อนเดียว) */
    public function periodsPerYear(string $frequency, ?array $days = null): int
    {
        return match ($frequency) {
            'daily'   => 365,
            'weekly'  => 52,
            'monthly' => 12,
            'custom'  => 12 * max(1, count($days ?? [])), // จำนวนวันที่เลือก × 12 เดือน
            'once'    => 0,
            default   => 12,
        };
    }

    /** label ความถี่ (custom = ระบุวันที่) */
    public function freqLabel(string $frequency, ?array $days = null): string
    {
        if ($frequency === 'custom' && !empty($days)) {
            return 'ทุกวันที่ ' . implode(', ', $days) . ' ของเดือน';
        }
        return self::FREQ_LABELS[$frequency] ?? $frequency;
    }

    /**
     * CAGR ย้อนหลัง (annualized) จากราคาทั้งหมดใน DB
     * คืน null ถ้าข้อมูลสั้นเกินไป (< 0.5 ปี) หรือราคาตั้งต้นไม่ถูกต้อง
     */
    public function cagr(Stock $stock): ?float
    {
        $first = StockPrice::where('stock_id', $stock->id)->orderBy('date', 'asc')->first(['date', 'close']);
        $last  = StockPrice::where('stock_id', $stock->id)->orderBy('date', 'desc')->first(['date', 'close']);

        if (!$first || !$last || $first->close <= 0 || $last->close <= 0) {
            return null;
        }

        $years = Carbon::parse($first->date)->diffInDays(Carbon::parse($last->date)) / 365.25;
        if ($years < 0.5) {
            return null;
        }

        return pow($last->close / $first->close, 1 / $years) - 1;
    }

    /** บีบ CAGR อัตโนมัติให้อยู่ในกรอบสมจริง (12% สำหรับพยากรณ์ระยะยาว) */
    private function clampCagr(float $cagr): float
    {
        return max(self::CAGR_MIN, min(self::CAGR_MAX, $cagr));
    }

    /** บีบค่าที่ผู้ใช้กรอกเอง — กว้างกว่า auto แต่ยังกันค่าเพ้อฝัน (±50%) */
    private function clampManual(float $cagr): float
    {
        return max(self::MANUAL_MIN, min(self::MANUAL_MAX, $cagr));
    }

    /**
     * Future value: ค่าตั้งต้น + เงินงวด (annuity due) ทบต้นตามจำนวนงวด/ปี
     */
    private function futureValue(float $initial, float $contribPerPeriod, float $cagr, int $years, int $ppy): float
    {
        $n = $ppy * $years;
        $r = $cagr / $ppy;

        $fvInitial = $initial * pow(1 + $cagr, $years);

        if (abs($r) < 1e-9) {
            $fvContrib = $contribPerPeriod * $n;
        } else {
            $fvContrib = $contribPerPeriod * ((pow(1 + $r, $n) - 1) / $r) * (1 + $r);
        }

        return $fvInitial + $fvContrib;
    }
}
