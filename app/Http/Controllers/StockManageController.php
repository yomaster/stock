<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesUserStocks;
use App\Models\Stock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class StockManageController extends Controller
{
    use ScopesUserStocks;

    public function index()
    {
        // เฉพาะสินทรัพย์ที่ user ติดตาม — เรียงตาม category ก่อน แล้วค่อย symbol
        $stocks = $this->userStocks()->orderBy('asset_category')->orderBy('symbol')->get();
        return view('stocks.manage', compact('stocks'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'symbol' => 'required|string|max:20',
            'years'  => 'required|integer|min:1|max:20',
        ]);

        $symbol = strtoupper(trim($validated['symbol']));
        $user   = $request->user();

        // หุ้นมีใน catalog แล้ว (อาจถูก user คนอื่นเพิ่มไว้) → แค่ attach ไม่ดึง Yahoo ซ้ำ
        $existing = Stock::where('symbol', $symbol)->first();
        if ($existing) {
            if ($user->stocks()->whereKey($existing->id)->exists()) {
                return $this->storeResponse($request, false, "หุ้น {$symbol} อยู่ในรายการติดตามของคุณแล้ว");
            }
            $user->stocks()->attach($existing->id);
            return $this->storeResponse($request, true, "เพิ่ม {$symbol} เข้ารายการติดตามแล้ว (ใช้ข้อมูลที่มีอยู่ในระบบ)");
        }

        // ยังไม่มีใน catalog → ดึงข้อมูลราคาจาก Yahoo Finance
        Artisan::call('app:fetch-stock-data', [
            'symbol'  => $symbol,
            '--years' => $validated['years'],
        ]);

        $stock = Stock::where('symbol', $symbol)->first();
        if (!$stock) {
            return $this->storeResponse($request, false, "ไม่พบข้อมูลหุ้น {$symbol} บน Yahoo Finance — ตรวจสอบ symbol ให้ถูกต้อง (เช่น PTT.BK, AAPL)");
        }

        // ผูกหุ้นใหม่ให้ user คนนี้
        $user->stocks()->syncWithoutDetaching([$stock->id]);

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
        $this->guardTracksStock($stock); // เฉพาะหุ้นที่ตัวเองติดตาม

        $years = (int) $request->input('years', 1);

        // ข้อมูลราคาใช้ร่วมกัน — refresh แล้วทุก user ที่ติดตามได้ประโยชน์
        Artisan::call('app:fetch-stock-data', [
            'symbol'  => $stock->symbol,
            '--years' => $years,
        ]);

        return back()->with('success', "อัปเดตข้อมูล {$stock->symbol} สำเร็จ");
    }

    public function destroy(Stock $stock)
    {
        $this->guardTracksStock($stock);
        $symbol = $stock->symbol;

        // เลิกติดตาม (detach) เท่านั้น — ไม่ลบ market data ที่ user อื่นอาจใช้อยู่
        auth()->user()->stocks()->detach($stock->id);

        // orphan cleanup: ไม่มีใครติดตามแล้ว → ลบหุ้น + prices + analysis (cascade)
        if ($stock->users()->count() === 0) {
            $stock->delete();
            return back()->with('success', "ลบหุ้น {$symbol} ออกจากระบบแล้ว (ไม่มีผู้ติดตามเหลือ)");
        }

        return back()->with('success', "เลิกติดตามหุ้น {$symbol} แล้ว");
    }
}
