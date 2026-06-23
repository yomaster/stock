<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesUserStocks;
use App\Models\AnalysisResult;
use App\Models\News;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Services\InvestmentService;
use Illuminate\Http\Request;

class StockController extends Controller
{
    use ScopesUserStocks;

    public function index()
    {
        $stocks = $this->userStocks()->with(['prices' => function ($q) {
            $q->orderBy('date', 'desc')->limit(2);
        }, 'analysisResults' => function ($q) {
            $q->orderBy('date', 'desc')->limit(1);
        }])->get()->map(function ($stock) {
            $prices = $stock->prices->sortByDesc('date')->values();
            $latest = $prices->first();
            $prev   = $prices->get(1);
            $change = ($latest && $prev && $prev->close > 0)
                ? (($latest->close - $prev->close) / $prev->close) * 100
                : null;
            $analysis = $stock->analysisResults->first();
            return [
                'id'           => $stock->id,
                'symbol'       => $stock->symbol,
                'name'         => $stock->name,
                'currency'     => $stock->currency,
                'exchange'     => $stock->exchange,
                'price'        => $latest?->close,
                'change'       => $change,
                'rating'       => $analysis?->rating,
                'risk_score'   => $analysis?->risk_score,
                'investment_style' => $analysis?->investment_style,
            ];
        });

        return view('stocks.index', compact('stocks'));
    }

    public function show(Stock $stock)
    {
        $this->guardTracksStock($stock); // กัน IDOR: ต้องเป็นหุ้นที่ user ติดตาม

        // ราคาย้อนหลังสูงสุด 10 ปี — ส่งทั้งหมดให้ JS แล้วกรองช่วงปีฝั่ง client ผ่านปุ่มฟิลเตอร์
        $tenYearsAgo = now()->subYears(10)->toDateString();
        $prices = StockPrice::where('stock_id', $stock->id)
            ->where('date', '>=', $tenYearsAgo)
            ->orderBy('date', 'asc')
            ->get(['date', 'close']);

        $latestPrice = $prices->last();

        // ผลวิเคราะห์ล่าสุด
        $analysis = AnalysisResult::where('stock_id', $stock->id)
            ->orderBy('date', 'desc')
            ->first();

        // ข่าวที่เกี่ยวข้องกับหุ้นตัวนี้โดยตรง
        $news = News::where('symbols', 'like', '%' . $stock->symbol . '%')
            ->orderBy('published_at', 'desc')
            ->limit(10)
            ->get();

        // ถ้าไม่มีข่าวเฉพาะหุ้น (พบบ่อยกับ ETF เช่น VOO) → แสดงข่าวตลาดโดยรวมแทน
        $newsIsFallback = false;
        if ($news->isEmpty()) {
            $news = News::orderBy('published_at', 'desc')->limit(6)->get();
            $newsIsFallback = true;
        }

        return view('stocks.show', compact('stock', 'prices', 'latestPrice', 'analysis', 'news', 'newsIsFallback'));
    }

    public function backtestForm(Stock $stock)
    {
        $this->guardTracksStock($stock);
        return view('stocks.backtest', compact('stock'));
    }

    public function backtestRun(Request $request, Stock $stock, InvestmentService $service)
    {
        $this->guardTracksStock($stock);
        $validated = $request->validate([
            'monthly_amount'    => 'required|numeric|min:100',
            'years'             => 'required|integer|min:1|max:20',
            'reinvest_dividends' => 'nullable|boolean',
        ]);

        $result = $service->backtestDCA(
            $stock->symbol,
            (float) $validated['monthly_amount'],
            (int) $validated['years'],
            1,
            $request->boolean('reinvest_dividends', true)
        );

        return view('stocks.backtest', compact('stock', 'result'));
    }

    public function analyzeForm(Stock $stock)
    {
        $this->guardTracksStock($stock);

        // ดึงผลวิเคราะห์ล่าสุดของ user คนนี้กับหุ้นตัวนี้ (ถ้ามี) — แสดงเลยโดยไม่ต้องเรียก AI ใหม่
        $latest = \App\Models\StockAnalysis::where('user_id', auth()->id())
            ->where('stock_id', $stock->id)
            ->latest()
            ->first();

        return view('stocks.analyze', compact('stock', 'latest'));
    }

    public function analyzeRun(Request $request, Stock $stock, InvestmentService $service)
    {
        $this->guardTracksStock($stock);
        $validated = $request->validate([
            'initial_amount'   => 'required|numeric|min:0',
            'monthly_amount'   => 'required|numeric|min:0',
            'years'            => 'required|integer|min:1|max:30',
            'display_currency' => 'nullable|string|in:THB,USD',
            'exchange_rate'    => 'nullable|numeric|min:1|max:200',
        ]);

        $displayCurrency = $validated['display_currency'] ?? $stock->currency;
        $exchangeRate    = (float) ($validated['exchange_rate'] ?? 36.0);

        $result = $service->projectFutureAI(
            $stock->symbol,
            (float) $validated['initial_amount'],
            (float) $validated['monthly_amount'],
            (int) $validated['years'],
            $displayCurrency,
            $exchangeRate
        );

        // คำนวณ data สำหรับกราฟการเติบโต (ทำฝั่ง server เพื่อส่งให้ JS วาด)
        $chartData = $result['success'] ? $this->buildProjectionChartData($result) : null;

        // เก็บผลล่าสุดไว้แสดงครั้งหน้า — เฉพาะที่ AI สำเร็จจริง (ไม่ cache ผล fallback)
        if (($result['ai_ok'] ?? false)) {
            \App\Models\StockAnalysis::create([
                'user_id'  => $request->user()->id,
                'stock_id' => $stock->id,
                'result'   => $result,
                'chart'    => $chartData,
            ]);
        }

        // AJAX (fetch) → ตอบกลับเป็น JSON พร้อม HTML partial + chart data
        if ($request->wantsJson()) {
            return response()->json([
                'success' => $result['success'],
                'html'    => view('stocks._analyze_result', compact('result'))->render(),
                'chart'   => $chartData,
            ]);
        }

        return view('stocks.analyze', compact('stock', 'result', 'chartData'));
    }

    /**
     * สร้างชุดข้อมูลกราฟการเติบโต Bull/Base/Bear + เส้นเงินลงทุนสะสม
     */
    private function buildProjectionChartData(array $result): array
    {
        $years   = $result['years'];
        $initial = $result['initial_amount'];
        $monthly = $result['monthly_amount'];
        $range   = range(0, $years);

        $makeData = function (float $cagr) use ($initial, $monthly, $range) {
            return array_map(function ($y) use ($initial, $monthly, $cagr) {
                $months = $y * 12;
                $r = ($cagr / 100) / 12;
                if ($r == 0) {
                    return round($initial + $monthly * $months);
                }
                $fvI = $initial * pow(1 + $r, $months);
                $fvD = $monthly * ((pow(1 + $r, $months) - 1) / $r) * (1 + $r);
                return round($fvI + $fvD);
            }, $range);
        };

        return [
            'labels'   => array_map(fn ($y) => "ปี {$y}", $range),
            'invested' => array_map(fn ($y) => round($initial + $monthly * 12 * $y), $range),
            'bull'     => $makeData($result['projections']['bull']['cagr']),
            'base'     => $makeData($result['projections']['base']['cagr']),
            'bear'     => $makeData($result['projections']['bear']['cagr']),
            'currency' => $result['currency'],
        ];
    }
}
