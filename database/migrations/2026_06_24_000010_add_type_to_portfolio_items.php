<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ประเภทธุรกรรม: buy (ซื้อ/เพิ่ม) หรือ sell (ขาย/หักออก)
 * ของเดิมทั้งหมด = buy
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolio_items', function (Blueprint $table) {
            $table->enum('type', ['buy', 'sell'])->default('buy')->after('stock_id');
        });
        DB::table('portfolio_items')->update(['type' => 'buy']);
    }

    public function down(): void
    {
        Schema::table('portfolio_items', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
