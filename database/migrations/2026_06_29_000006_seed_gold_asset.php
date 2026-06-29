<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seed สินทรัพย์ทองคำ singleton — ทองคำแท่ง 96.5% (ราคาอ้างอิงสมาคมค้าทองคำ GTA)
     * ทุก user ใช้ asset ตัวเดียวกัน (เหมือนหุ้น/กองทุนที่ใช้ market data ร่วม)
     * ราคาเก็บใน stock_prices: close = รับซื้อ (valuation), open = ขายออก (ราคาซื้อ)
     */
    public function up(): void
    {
        DB::table('stocks')->insertOrIgnore([
            'symbol'         => 'GOLD',
            'name'           => 'ทองคำแท่ง 96.5%',
            'currency'       => 'THB',
            'exchange'       => 'GTA',
            'type'           => 'GOLD',
            'asset_category' => 'gold',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('stocks')->where('symbol', 'GOLD')->where('asset_category', 'gold')->delete();
    }
};
