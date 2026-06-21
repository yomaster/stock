<?php

namespace App\Http\Controllers;

use App\Console\Commands\FetchStockData;
use App\Models\Stock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class StockManageController extends Controller
{
    public function index()
    {
        $stocks = Stock::orderBy('symbol')->get();
        return view('stocks.manage', compact('stocks'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'symbol' => 'required|string|max:20',
            'years'  => 'required|integer|min:1|max:20',
        ]);

        $symbol = strtoupper(trim($validated['symbol']));

        // ตรวจว่ามีในระบบแล้วหรือยัง
        if (Stock::where('symbol', $symbol)->exists()) {
            return $this->storeResponse($request, false, "หุ้น {$symbol} มีอยู่ในระบบแล้ว");
        }

        // ดึงข้อมูลราคาจาก Yahoo Finance ผ่าน Artisan command
        Artisan::call('app:fetch-stock-data', [
            'symbol'  => $symbol,
            '--years' => $validated['years'],
        ]);

        if (!Stock::where('symbol', $symbol)->exists()) {
            return $this->storeResponse($request, false, "ไม่พบข้อมูลหุ้น {$symbol} บน Yahoo Finance — ตรวจสอบ symbol ให้ถูกต้อง (เช่น PTT.BK, AAPL)");
        }

        // ดึงข่าวรายหุ้น + แปลไทย (ไม่ให้ error ขัดการเพิ่มหุ้นที่สำเร็จแล้ว)
        try {
            Artisan::call('app:fetch-stock-news', ['symbol' => $symbol, '--count' => 10]);
            Artisan::call('app:summarize-news', ['--limit' => 15, '--batch' => 15]);
        } catch (\Throwable $e) {
            // เงียบไว้ — ข่าวดึงไม่ได้ไม่ใช่เรื่องคอขาดบาดตาย หุ้นเพิ่มสำเร็จแล้ว
        }

        return $this->storeResponse($request, true, "เพิ่มหุ้น {$symbol} สำเร็จ พร้อมข้อมูลย้อนหลัง {$validated['years']} ปี และดึงข่าวล่าสุดแล้ว");
    }

    /**
     * ตอบกลับแบบ JSON (AJAX) หรือ redirect (ปกติ)
     */
    private function storeResponse(Request $request, bool $ok, string $message)
    {
        if ($request->wantsJson()) {
            return response()->json(['success' => $ok, 'message' => $message], $ok ? 200 : 422);
        }
        return back()->with($ok ? 'success' : 'error', $message);
    }

    public function refresh(Stock $stock, Request $request)
    {
        $years = (int) $request->input('years', 1);

        Artisan::call('app:fetch-stock-data', [
            'symbol'  => $stock->symbol,
            '--years' => $years,
        ]);

        return back()->with('success', "อัปเดตข้อมูล {$stock->symbol} สำเร็จ");
    }

    public function destroy(Stock $stock)
    {
        $symbol = $stock->symbol;
        $stock->delete(); // cascade ลบ stock_prices + analysis_results ด้วย

        return back()->with('success', "ลบหุ้น {$symbol} ออกจากระบบแล้ว");
    }
}
