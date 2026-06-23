<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesUserStocks;
use App\Models\AnalysisResult;
use App\Models\News;
use App\Models\StockPrice;

class DashboardController extends Controller
{
    use ScopesUserStocks;

    public function index()
    {
        // ขอบเขต = หุ้นที่ user ปัจจุบันติดตามเท่านั้น (market data ใช้ร่วมกัน)
        $stockIds = $this->userStockIds();
        $symbols  = $this->userStockSymbols();

        $stockCount = count($stockIds);
        $priceCount = StockPrice::whereIn('stock_id', $stockIds)->count();
        $newsCount  = $this->newsForSymbols($symbols)->count();

        // หุ้นที่ถูกวิเคราะห์ล่าสุด (เฉพาะหุ้นที่ติดตาม)
        $latestAnalyses = AnalysisResult::with('stock')
            ->whereIn('stock_id', $stockIds)
            ->orderBy('date', 'desc')
            ->limit(5)
            ->get();

        // ข่าวล่าสุด 5 ข่าว (เฉพาะหุ้นที่ติดตาม)
        $latestNews = $this->newsForSymbols($symbols)
            ->orderBy('published_at', 'desc')->limit(5)->get();

        // หุ้นทั้งหมดพร้อมราคาล่าสุด
        $stocks = $this->userStocks()->with(['prices' => function ($q) {
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

    /** ข่าวที่เกี่ยวกับ symbols ที่กำหนด (news.symbols เก็บเป็น string รวม) */
    private function newsForSymbols(array $symbols)
    {
        return News::where(function ($q) use ($symbols) {
            if (empty($symbols)) {
                $q->whereRaw('1 = 0'); // ไม่ติดตามหุ้นเลย → ไม่มีข่าว
                return;
            }
            foreach ($symbols as $sym) {
                $q->orWhere('symbols', 'like', '%' . $sym . '%');
            }
        });
    }
}
