<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolio_items', function (Blueprint $table) {
            // เงินที่ลงทุนจริง + สกุลที่จ่าย (Dime ซื้อแบบใส่เงิน → เศษหุ้น)
            // shares/purchase_price จะถูกคำนวณย้อนหลังจากราคาวันที่ซื้อ
            $table->decimal('invested_amount', 15, 2)->nullable()->after('stock_id');
            $table->string('invested_currency', 3)->nullable()->after('invested_amount');
        });
    }

    public function down(): void
    {
        Schema::table('portfolio_items', function (Blueprint $table) {
            $table->dropColumn(['invested_amount', 'invested_currency']);
        });
    }
};
