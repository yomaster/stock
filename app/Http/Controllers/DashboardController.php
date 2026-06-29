<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesUserStocks;
use App\Models\AnalysisResult;
use App\Models\News;
use App\Models\StockPrice;
use App\Services\PortfolioService;

class DashboardController extends Controller
{
    use ScopesUserStocks;

    public function __construct(private PortfolioService $portfolio) {}

    public function index()
    {
        // ขอบเขต = หุ้นที่ user ปัจจุบันติดตามเท่านั้น (market data ใช้ร่วมกัน)
        $stockIds = $this->userStockIds();
        $symbols  = $this->userStockSymbols();

        $stockCount = count($stockIds);
        $priceCount = StockPrice::whereIn('stock_id', $stockIds)->count();
        $newsCount  = $this->newsForSymbols($symbols)->count();

        // บทประเมินหุ้นจาก AI ล่าสุด (Rating/ความเสี่ยง/สรุป) จากตาราง analysis_results
        // = ตัวเดียวกับ panel "ผลวิเคราะห์ AI" หน้า stock — กรองเฉพาะหุ้นที่ user ติดตาม
        // (ไม่มีผล fallback ค้างแล้ว เพราะ projectFutureAI บันทึกเฉพาะตอน AI สำเร็จจริง)
        $latestAnalyses = AnalysisResult::with('stock')
            ->whereIn('stock_id', $stockIds)
            ->orderByDesc('date')->orderByDesc('id')
            ->get()
            ->unique('stock_id') // ล่าสุดต่อหุ้น (กันโชว์หุ้นเดียวซ้ำหลายวัน)
            ->take(5)
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
                'id'             => $stock->id,
                'symbol'         => $stock->symbol,
                'name'           => $stock->name,
                'currency'       => $stock->currency,
                'asset_category' => $stock->asset_category,
                'price'          => $latest?->close,
                'change'         => $change,
            ];
        });

        // สรุปมูลค่าพอร์ตแยกตามชนิดสินทรัพย์ (รวมทุกพอร์ตของ user)
        $assetBreakdown = $this->assetBreakdown(auth()->user());

        return view('dashboard', compact(
            'stockCount', 'priceCount', 'newsCount',
            'latestAnalyses', 'stocks', 'assetBreakdown'
        ));
    }

    /**
     * รวมมูลค่า/ต้นทุน/กำไร แยกตามชนิดสินทรัพย์ ข้ามทุกพอร์ตของ user
     * คืน [category => ['value','cost','pnl','pnl_pct','count'], ...] เฉพาะชนิดที่มีของ
     */
    private function assetBreakdown($user): array
    {
        $agg = [];
        foreach ($user->portfolios as $pf) {
            foreach ($this->portfolio->buildHoldings($pf)['positions'] as $pos) {
                $cat = $pos['asset_category'] ?? 'stock';
                $agg[$cat] ??= ['value' => 0, 'cost' => 0, 'count' => 0];
                $agg[$cat]['value'] += $pos['value_thb'];
                $agg[$cat]['cost']  += $pos['cost_thb'];
                $agg[$cat]['count']++;
            }
        }
        foreach ($agg as &$b) {
            $b['pnl']     = $b['value'] - $b['cost'];
            $b['pnl_pct'] = $b['cost'] > 0 ? ($b['pnl'] / $b['cost']) * 100 : 0;
        }
        return $agg;
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
