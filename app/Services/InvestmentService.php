<?php

namespace App\Services;

use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\News;
use Illuminate\Support\Carbon;

class InvestmentService
{
    /**
     * จำลองการลงทุนแบบ DCA ย้อนหลัง (Historical Backtest)
     *
     * @param string $symbol รหัสหุ้น (เช่น PTT.BK, AAPL)
     * @param float $monthlyAmount จำนวนเงินที่ลงทุนต่อเดือน (บาท/ดอลลาร์)
     * @param int $years จำนวนปีย้อนหลัง
     * @param int $buyDayOnMonth วันที่ที่จะทำการซื้อในแต่ละเดือน (ค่าเริ่มต้นคือวันที่ 1 หรือวันทำการแรก)
     * @param bool $reinvestDividends นำเงินปันผลไปซื้อหุ้นเพิ่มโดยอัตโนมัติ (ทบต้น)
     * @return array
     */
    public function backtestDCA(string $symbol, float $monthlyAmount, int $years, int $buyDayOnMonth = 1, bool $reinvestDividends = true): array
    {
        $stock = Stock::where('symbol', strtoupper($symbol))->first();
        if (!$stock) {
            return ['success' => false, 'error' => "ไม่พบข้อมูลหุ้น {$symbol} ในระบบ"];
        }

        $endDate = Carbon::now();
        $startDate = Carbon::now()->subYears($years);

        // ดึงราคาหุ้นและปันผลทั้งหมดในช่วงเวลา เรียงตามวันที่จากอดีตไปปัจจุบัน
        $prices = StockPrice::where('stock_id', $stock->id)
            ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
            ->orderBy('date', 'asc')
            ->get();

        if ($prices->isEmpty()) {
            return ['success' => false, 'error' => 'ไม่มีข้อมูลราคาหุ้นในช่วงเวลาที่ระบุ'];
        }

        $totalInvested = 0.0;
        $totalShares = 0.0;
        $dividendReceived = 0.0;
        $monthlyTransactions = [];
        $dividendLogs = [];

        // แบ่งกลุ่มราคาตาม ปี-เดือน เพื่อหาการซื้อรายเดือน
        $pricesByMonth = [];
        foreach ($prices as $price) {
            $monthKey = Carbon::parse($price->date)->format('Y-m');
            $pricesByMonth[$monthKey][] = $price;
        }

        // เริ่มต้นคำนวณจำลองทีละเดือน
        foreach ($pricesByMonth as $monthKey => $monthPrices) {
            // ค้นหาวันทำการแรกในเดือนนั้นที่มีการบันทึกราคา (จำลองการซื้อ DCA ต้นเดือน)
            $buyPriceRecord = $monthPrices[0]; // สมมติซื้อวันทำการแรกสุดของเดือน
            
            // เพิ่มเงินทุนสะสมและซื้อหุ้นเพิ่ม
            $totalInvested += $monthlyAmount;
            $sharesBought = $monthlyAmount / $buyPriceRecord->close;
            $totalShares += $sharesBought;

            $monthlyTransactions[] = [
                'date' => $buyPriceRecord->date,
                'price' => $buyPriceRecord->close,
                'amount_invested' => $monthlyAmount,
                'shares_bought' => $sharesBought,
                'total_shares' => $totalShares
            ];

            // ตรวจสอบว่าในเดือนนี้มีวันจ่ายเงินปันผล (Dividend) หรือไม่
            foreach ($monthPrices as $price) {
                if ($price->dividends > 0) {
                    // เงินปันผลที่ได้รับ = จำนวนหุ้นที่มีอยู่ ณ วันนั้น * อัตราปันผลต่อหุ้น
                    $divAmount = $totalShares * $price->dividends;
                    $dividendReceived += $divAmount;

                    $dividendLogs[] = [
                        'date' => $price->date,
                        'dividend_per_share' => $price->dividends,
                        'amount_received' => $divAmount,
                        'current_shares' => $totalShares
                    ];

                    // หากผู้ใช้เลือกให้ปันผลทบต้น (Reinvest)
                    if ($reinvestDividends) {
                        $reinvestedShares = $divAmount / $price->close;
                        $totalShares += $reinvestedShares;

                        $monthlyTransactions[] = [
                            'date' => $price->date,
                            'price' => $price->close,
                            'amount_invested' => $divAmount,
                            'shares_bought' => $reinvestedShares,
                            'total_shares' => $totalShares,
                            'type' => 'dividend_reinvestment'
                        ];
                    }
                }
            }
        }

        // ช่วงข้อมูลจริงที่ใช้คำนวณ — อาจสั้นกว่า $years ที่ขอ ถ้าหุ้นมีราคาใน DB ไม่ครบ
        //   (เช่น import มาแค่ 5 ปี แต่ผู้ใช้ขอ 10 ปี) → ส่งกลับไปให้ caller โชว์ช่วงจริง ไม่ให้ label หลอกตา
        $actualStart  = Carbon::parse($prices->first()->date);
        $actualEnd    = Carbon::parse($prices->last()->date);
        $actualMonths = count($pricesByMonth);

        // ดึงราคาหุ้นล่าสุด ณ ปัจจุบัน
        $latestPriceRecord = $prices->last();
        $currentPrice = $latestPriceRecord->close;
        
        // คำนวณมูลค่าพอร์ตปัจจุบัน
        $portfolioValue = $totalShares * $currentPrice;
        
        // หากไม่ได้นำเงินปันผลไปซื้อทบต้น ให้นำเงินปันผลมารวมเป็นเงินสดในพอร์ตแทน
        if (!$reinvestDividends) {
            $portfolioValue += $dividendReceived;
        }

        $profitLossVal = $portfolioValue - $totalInvested;
        $profitLossPct = $totalInvested > 0 ? ($profitLossVal / $totalInvested) * 100 : 0;

        return [
            'success' => true,
            'symbol' => $symbol,
            'currency' => $stock->currency,
            'years' => $years,
            // ช่วงเวลาจริงที่มีข้อมูล — ใช้โชว์แทน $years เมื่อข้อมูลไม่ครบ
            'actual_start' => $actualStart->toDateString(),
            'actual_end' => $actualEnd->toDateString(),
            'actual_months' => $actualMonths,
            'total_invested' => $totalInvested,
            'total_shares' => $totalShares,
            'total_dividends_received' => $dividendReceived,
            'portfolio_value' => $portfolioValue,
            'profit_loss_value' => $profitLossVal,
            'profit_loss_percentage' => $profitLossPct,
            'latest_price' => $currentPrice,
            'transactions' => $monthlyTransactions,
            'dividends' => $dividendLogs
        ];
    }

    /**
     * คาดการณ์อนาคตด้วย AI และสูตรการคำนวณทางการเงิน
     */
    /**
     * แปลงผลลัพธ์เป็นสกุลเงินที่ต้องการแสดง
     * displayCurrency = 'THB' จะคูณด้วย exchangeRate (USD→THB)
     */
    public function projectFutureAI(string $symbol, float $initialAmount, float $monthlyAmount, int $years, string $displayCurrency = '', float $exchangeRate = 1.0): array
    {
        $stock = Stock::where('symbol', strtoupper($symbol))->first();
        if (!$stock) {
            return ['success' => false, 'error' => "ไม่พบข้อมูลหุ้น {$symbol} ในระบบ"];
        }

        // ดึงราคาล่าสุด
        $latestPrice = StockPrice::where('stock_id', $stock->id)->orderBy('date', 'desc')->first();
        if (!$latestPrice) {
            return ['success' => false, 'error' => "ไม่มีข้อมูลราคาหุ้นสำหรับ {$symbol}"];
        }

        // ดึงข่าวที่เกี่ยวข้อง
        $relatedNews = News::where('symbols', 'like', '%' . $stock->symbol . '%')
            ->orderBy('published_at', 'desc')
            ->limit(10)
            ->get();

        $newsContext = "";
        foreach ($relatedNews as $news) {
            $newsContext .= "- ข่าว: {$news->title} (แหล่งที่มา: {$news->source}, วันที่: {$news->published_at})\n";
            if ($news->summary) {
                $newsContext .= "  เนื้อหาหลัก: " . substr($news->summary, 0, 200) . "...\n";
            }
        }

        // คำนวณ CAGR ในอดีตเบื้องต้น
        $oneYearAgo = Carbon::now()->subYear()->toDateString();
        $priceOneYearAgo = StockPrice::where('stock_id', $stock->id)
            ->where('date', '>=', $oneYearAgo)
            ->orderBy('date', 'asc')
            ->first();

        $historicalGrowthStr = "ไม่มีข้อมูลประวัติยาวพอ";
        if ($priceOneYearAgo && $priceOneYearAgo->close > 0) {
            $growth = (($latestPrice->close - $priceOneYearAgo->close) / $priceOneYearAgo->close) * 100;
            $historicalGrowthStr = "ราคา 1 ปีที่แล้ว: {$priceOneYearAgo->close} {$stock->currency}, ราคาปัจจุบัน: {$latestPrice->close} {$stock->currency} (เติบโตสะสม 1 ปี: " . number_format($growth, 2) . "%)";
        }

        // เตรียม Prompt ส่งให้ Gemini
        $prompt = "คุณคือนักวิเคราะห์หลักทรัพย์ผู้เชี่ยวชาญระดับโลกและที่ปรึกษาทางการเงินส่วนบุคคล
วิเคราะห์แนวโน้มในอนาคตของหุ้น {$stock->symbol} ({$stock->name}) สำหรับการลงทุนระยะยาว {$years} ปีข้างหน้า

ข้อมูลประกอบการวิเคราะห์:
- ราคาล่าสุด: {$latestPrice->close} {$stock->currency}
- การเติบโตในอดีต: {$historicalGrowthStr}
- ข่าวล่าสุดที่มีผลต่อราคาและการดำเนินงานของบริษัท:
{$newsContext}

ภารกิจของคุณ:
1. ประเมินและทำนายอัตราการเติบโตเฉลี่ยสะสมต่อปี (CAGR %) ในอีก {$years} ปีข้างหน้าของหุ้นตัวนี้ โดยแบ่งเป็น 3 สถานการณ์ (Scenarios):
   - Bull Case: สถานการณ์ที่บริษัทดำเนินงานได้ดีเยี่ยม ตลาดหนุน
   - Base Case: สถานการณ์ปกติตามแนวโน้มเศรษฐกิจและธุรกิจของบริษัท
   - Bear Case: สถานการณ์ย่ำแย่ เกิดวิกฤต หรือธุรกิจหดตัว
2. ประเมินคะแนนความเสี่ยง (Risk Score) ตั้งแต่ 1 (เสี่ยงต่ำมาก ปลอดภัยสูง) ถึง 10 (เสี่ยงสูงมาก เป็นหุ้นซิ่ง/ปั่น)
3. สรุปความเห็นของบทวิเคราะห์เป็นภาษาไทยสั้นๆ กระชับ

สำคัญมาก: ให้ตอบกลับมาเป็นข้อมูลรูปแบบ JSON เท่านั้น โดยห้ามมี Markdown หรือข้อความอธิบายใดๆ นอกเหนือจาก JSON Object ดังต่อไปนี้:
{
  \"bull_cagr\": (ตัวเลขทศนิยม เช่น 12.5),
  \"base_cagr\": (ตัวเลขทศนิยม เช่น 7.2),
  \"bear_cagr\": (ตัวเลขทศนิยม เช่น -3.5),
  \"bull_rationale\": \"(เหตุผลสั้นภาษาไทย)\",
  \"base_rationale\": \"(เหตุผลสั้นภาษาไทย)\",
  \"bear_rationale\": \"(เหตุผลสั้นภาษาไทย)\",
  \"risk_score\": (ตัวเลขจำนวนเต็ม 1-10),
  \"summary\": \"(บทวิเคราะห์โดยรวมภาษาไทย กระชับ)\"
}";

        $gemini = app(GeminiService::class);
        $aiResponse = $gemini->generateText($prompt);

        $cleanJson = trim((string) $aiResponse); // guard null (PHP 8.4 deprecation)
        if (str_starts_with($cleanJson, '```json')) {
            $cleanJson = substr($cleanJson, 7);
        }
        if (str_starts_with($cleanJson, '```')) {
            $cleanJson = substr($cleanJson, 3);
        }
        if (str_ends_with($cleanJson, '```')) {
            $cleanJson = substr($cleanJson, 0, -3);
        }
        $cleanJson = trim($cleanJson);

        $aiData = json_decode($cleanJson, true);

        // ai_ok = AI ตอบกลับสำเร็จจริง (ไม่ใช่ fallback) — ใช้ตัดสินใจว่าจะ cache ผลไหม
        $aiOk = ($aiData && isset($aiData['base_cagr']));
        if (!$aiOk) {
            $aiData = [
                'bull_cagr' => 10.0,
                'base_cagr' => 5.0,
                'bear_cagr' => -2.0,
                'bull_rationale' => 'คาดการณ์อัตราการเติบโตแบบก้าวหน้าโดยอิงตามสถิติเฉลี่ย',
                'base_rationale' => 'คาดการณ์อัตราการเติบโตระดับปกติแบบทั่วไป',
                'bear_rationale' => 'ประเมินความเสี่ยงกรณีตลาดถดถอยและวิกฤตเศรษฐกิจ',
                'risk_score' => 5,
                'summary' => 'ระบุข้อผิดพลาดในการดึงข้อมูลจาก AI ระบบจึงแสดงผลการคำนวณเบื้องต้นจากการเติบโตมาตรฐานทั่วไป'
            ];
        }

        // ถ้าผู้ใช้เลือกแสดงผลเป็น THB ให้แปลง input ก่อนคำนวณ
        // (CAGR เป็น % ไม่เปลี่ยนตามสกุลเงิน — แค่ scale ตัวเลขด้วย exchangeRate)
        $inputMultiplier = ($displayCurrency && $displayCurrency !== $stock->currency && $exchangeRate > 0) ? $exchangeRate : 1.0;
        $displayCurrencyLabel = $displayCurrency ?: $stock->currency;

        $scaledInitial = $initialAmount;   // amount ที่ผู้ใช้กรอกมาในสกุล displayCurrency
        $scaledMonthly = $monthlyAmount;

        // คำนวณเงินสะสมตามสูตรทางการเงินในแต่ละกรณี (Bull, Base, Bear)
        $projection = [];
        $scenarios = ['bull' => $aiData['bull_cagr'], 'base' => $aiData['base_cagr'], 'bear' => $aiData['bear_cagr']];

        foreach ($scenarios as $key => $cagr) {
            $projectedValue = $this->calculateFutureValue($scaledInitial, $scaledMonthly, $cagr, $years);
            $totalInvested = $scaledInitial + ($scaledMonthly * 12 * $years);
            $profitVal = $projectedValue - $totalInvested;
            $profitPct = $totalInvested > 0 ? ($profitVal / $totalInvested) * 100 : 0;

            $projection[$key] = [
                'cagr' => $cagr,
                'rationale' => $aiData[$key . '_rationale'],
                'total_invested' => $totalInvested,
                'future_value' => $projectedValue,
                'profit_loss_value' => $profitVal,
                'profit_loss_percentage' => $profitPct
            ];
        }

        // บันทึกผลวิเคราะห์ลงฐานข้อมูล — เฉพาะที่ AI สำเร็จจริง (ไม่เก็บผล fallback ที่เป็นข้อความ error)
        if ($aiOk) {
            $stock->analysisResults()->updateOrCreate(
                ['date' => Carbon::now()->toDateString()],
                [
                    'rating' => $aiData['base_cagr'] > 10 ? 'Buy' : ($aiData['base_cagr'] > 4 ? 'Hold' : 'Avoid'),
                    'investment_style' => $aiData['base_cagr'] > 12 ? 'Growth' : ($aiData['risk_score'] > 7 ? 'Momentum' : 'Dividend'),
                    'risk_score' => $aiData['risk_score'],
                    'summary' => $aiData['summary'],
                    'projection_details' => json_encode($projection)
                ]
            );
        }

        return [
            'success' => true,
            'ai_ok' => $aiOk,   // false = ใช้ค่า fallback (AI ล้มเหลว) → ไม่ควร cache
            'symbol' => $symbol,
            'name' => $stock->name,
            'currency' => $displayCurrencyLabel,
            'stock_currency' => $stock->currency,
            'years' => $years,
            'initial_amount' => $initialAmount,
            'monthly_amount' => $monthlyAmount,
            'exchange_rate' => $exchangeRate,
            'risk_score' => $aiData['risk_score'],
            'summary' => $aiData['summary'],
            'projections' => $projection
        ];
    }

    /**
     * คำนวณอนาคตสะสมแบบ DCA ดอกเบี้ยทบต้นรายเดือน
     */
    private function calculateFutureValue(float $initial, float $monthly, float $cagr, int $years): float
    {
        $months = $years * 12;
        $r = ($cagr / 100) / 12;

        if ($r == 0) {
            return $initial + ($monthly * $months);
        }

        $fvInitial = $initial * pow(1 + $r, $months);
        $fvDCA = $monthly * ((pow(1 + $r, $months) - 1) / $r) * (1 + $r);

        return $fvInitial + $fvDCA;
    }
}
