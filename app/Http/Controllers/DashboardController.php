<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesUserStocks;
use App\Models\News;
use App\Models\StockAnalysis;
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

        // ผลวิเคราะห์ AI ล่าสุดของ user คนนี้ (แยกรายคน จากตาราง stock_analyses)
        // ดึงล่าสุดต่อหุ้น (unique stock_id) แล้วเอา 5 ตัวที่วิเคราะห์ล่าสุด
        $latestAnalyses = StockAnalysis::with('stock')
            ->where('user_id', auth()->id())
            ->whereIn('stock_id', $stockIds)
            ->orderByDesc('created_at')
            ->get()
            ->unique('stock_id')
            ->take(5)
            ->map(function ($a) {
                $r = $a->result ?? [];
                $baseCagr = $r['projections']['base']['cagr'] ?? null;
                // rating: ใช้ที่เก็บไว้ ถ้าไม่มีก็ derive จาก base CAGR (รองรับ row เก่าที่ไม่มี rating)
                $rating = $r['rating'] ?? ($baseCagr === null ? '—'
                    : ($baseCagr > 10 ? 'Buy' : ($baseCagr > 4 ? 'Hold' : 'Avoid')));
                return (object) [
                    'stock_id'   => $a->stock_id,
                    'stock'      => $a->stock,
                    'rating'     => $rating,
                    'risk_score' => $r['risk_score'] ?? '-',
                    'summary'    => $r['summary'] ?? '',
                ];
            })
            ->values();

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
            'latestAnalyses', 'stocks'
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
