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
            return back()->with('error', "หุ้น {$symbol} มีอยู่ในระบบแล้ว");
        }

        // ดึงข้อมูลจาก Yahoo Finance ผ่าน Artisan command
        $exitCode = Artisan::call('app:fetch-stock-data', [
            'symbol'  => $symbol,
            '--years' => $validated['years'],
        ]);

        if (!Stock::where('symbol', $symbol)->exists()) {
            return back()->with('error', "ไม่พบข้อมูลหุ้น {$symbol} บน Yahoo Finance — ตรวจสอบ symbol ให้ถูกต้อง (เช่น PTT.BK, AAPL)");
        }

        return back()->with('success', "เพิ่มหุ้น {$symbol} สำเร็จ พร้อมข้อมูลย้อนหลัง {$validated['years']} ปี");
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
