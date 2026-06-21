<?php

namespace App\Http\Controllers;

use App\Models\AnalysisResult;
use App\Models\News;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Services\InvestmentService;
use Illuminate\Http\Request;

class StockController extends Controller
{
    public function index()
    {
        $stocks = Stock::with(['prices' => function ($q) {
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
        // ราคาย้อนหลัง 1 ปี สำหรับกราฟ
        $prices = StockPrice::where('stock_id', $stock->id)
            ->orderBy('date', 'desc')
            ->limit(365)
            ->get()
            ->sortBy('date')
            ->values();

        $latestPrice = $prices->last();

        // ผลวิเคราะห์ล่าสุด
        $analysis = AnalysisResult::where('stock_id', $stock->id)
            ->orderBy('date', 'desc')
            ->first();

        // ข่าวที่เกี่ยวข้อง
        $news = News::where('symbols', 'like', '%' . $stock->symbol . '%')
            ->orderBy('published_at', 'desc')
            ->limit(10)
            ->get();

        return view('stocks.show', compact('stock', 'prices', 'latestPrice', 'analysis', 'news'));
    }

    public function backtestForm(Stock $stock)
    {
        return view('stocks.backtest', compact('stock'));
    }

    public function backtestRun(Request $request, Stock $stock, InvestmentService $service)
    {
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
        return view('stocks.analyze', compact('stock'));
    }

    public function analyzeRun(Request $request, Stock $stock, InvestmentService $service)
    {
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

        return view('stocks.analyze', compact('stock', 'result'));
    }
}
