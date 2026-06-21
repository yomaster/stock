<?php

namespace App\Http\Controllers;

use App\Models\AnalysisResult;
use App\Models\News;
use App\Models\Stock;
use App\Models\StockPrice;

class DashboardController extends Controller
{
    public function index()
    {
        $stockCount  = Stock::count();
        $priceCount  = StockPrice::count();
        $newsCount   = News::count();

        // หุ้นที่ถูกวิเคราะห์ล่าสุด
        $latestAnalyses = AnalysisResult::with('stock')
            ->orderBy('date', 'desc')
            ->limit(5)
            ->get();

        // ข่าวล่าสุด 5 ข้าว
        $latestNews = News::orderBy('published_at', 'desc')->limit(5)->get();

        // หุ้นทั้งหมดพร้อมราคาล่าสุด
        $stocks = Stock::with(['prices' => function ($q) {
            $q->orderBy('date', 'desc')->limit(2);
        }])->get()->map(function ($stock) {
            $prices = $stock->prices->sortByDesc('date')->values();
            $latest = $prices->first();
            $prev   = $prices->get(1);
            $change = ($latest && $prev && $prev->close > 0)
                ? (($latest->close - $prev->close) / $prev->close) * 100
                : null;
            return [
                'id'       => $stock->id,
                'symbol'   => $stock->symbol,
                'name'     => $stock->name,
                'currency' => $stock->currency,
                'price'    => $latest?->close,
                'change'   => $change,
            ];
        });

        return view('dashboard', compact(
            'stockCount', 'priceCount', 'newsCount',
            'latestAnalyses', 'latestNews', 'stocks'
        ));
    }
}
