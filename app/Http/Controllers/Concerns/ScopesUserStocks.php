<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Stock;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Auth;

/**
 * ตัวช่วยจำกัดขอบเขตข้อมูลหุ้นให้เป็นของ user ปัจจุบัน (pivot user_stocks)
 * market data (stock_prices/news) ใช้ร่วมกัน — กรองที่ "หุ้นที่ติดตาม" เท่านั้น
 */
trait ScopesUserStocks
{
    /** relation หุ้นที่ user ปัจจุบันติดตาม (ใช้ต่อ query / pluck ids ได้) */
    protected function userStocks(): BelongsToMany
    {
        return Auth::user()->stocks();
    }

    /** id ของหุ้นที่ user ติดตาม (ใช้ whereIn กับ market data) */
    protected function userStockIds(): array
    {
        return $this->userStocks()->pluck('stocks.id')->all();
    }

    /** symbols ของหุ้นที่ user ติดตาม */
    protected function userStockSymbols(): array
    {
        return $this->userStocks()->pluck('symbol')->all();
    }

    /** กัน IDOR: user ต้องติดตามหุ้นนี้ ไม่งั้น 404 */
    protected function guardTracksStock(Stock $stock): void
    {
        if (!$this->userStocks()->whereKey($stock->getKey())->exists()) {
            abort(404);
        }
    }
}
