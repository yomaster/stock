<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stocks', function (Blueprint $table) {
            // proj_id ของ SEC — ใช้เป็น key ดึง NAV รายวันจาก FundDailyInfo
            // (กองทุนไทยเท่านั้น; หุ้น/ETF เป็น null) index ไว้เผื่อ refresh ตาม proj_id
            $table->string('sec_proj_id')->nullable()->index()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('stocks', function (Blueprint $table) {
            $table->dropColumn('sec_proj_id');
        });
    }
};
