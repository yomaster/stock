<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * เวลา execution จริงจากโบรก (ใช้ตอน import จากภาพ) — dedup กันเพิ่มซ้ำ
 * item ที่กรอกมือจะเป็น null (ไม่ชนกัน)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolio_items', function (Blueprint $table) {
            $table->dateTime('executed_at')->nullable()->after('purchase_date');
            $table->index(['portfolio_id', 'stock_id', 'executed_at'], 'pi_dedup_idx');
        });
    }

    public function down(): void
    {
        Schema::table('portfolio_items', function (Blueprint $table) {
            $table->dropIndex('pi_dedup_idx');
            $table->dropColumn('executed_at');
        });
    }
};
