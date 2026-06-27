<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * เรท FX จริงจากโบรก (1 USD = ? THB) ต่อรายการ — override เรทตลาด
 * null = ใช้เรท Yahoo ตามวันธุรกรรม (ค่า default)
 * ใช้กับรายการสกุล USD เท่านั้น (THB ไม่ต้องแปลง)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolio_items', function (Blueprint $table) {
            $table->decimal('fx_rate', 12, 6)->nullable()->after('invested_currency');
        });
    }

    public function down(): void
    {
        Schema::table('portfolio_items', function (Blueprint $table) {
            $table->dropColumn('fx_rate');
        });
    }
};
