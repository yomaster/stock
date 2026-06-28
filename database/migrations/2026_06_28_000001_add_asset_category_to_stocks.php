<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stocks', function (Blueprint $table) {
            // กลุ่ม asset สำหรับ display/filter — แยกจาก Yahoo's instrumentType ที่ละเอียดกว่า
            $table->enum('asset_category', ['stock', 'etf', 'fund', 'gold'])
                ->default('stock')
                ->after('type');
        });

        // backfill จาก Yahoo type ที่มีอยู่แล้ว
        DB::statement("
            UPDATE stocks SET asset_category = CASE
                WHEN type = 'ETF'        THEN 'etf'
                WHEN type = 'MUTUALFUND' THEN 'fund'
                ELSE 'stock'
            END
        ");
    }

    public function down(): void
    {
        Schema::table('stocks', function (Blueprint $table) {
            $table->dropColumn('asset_category');
        });
    }
};
