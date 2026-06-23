<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ประวัติผลวิเคราะห์ AI รายตัว แยกตาม user (mirror แนวคิด portfolio_health_checks)
 * เก็บผลล่าสุดไว้แสดงโดยไม่ต้องเรียก AI ใหม่ — ประหยัด token
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_id')->constrained()->cascadeOnDelete();
            $table->json('result');             // ผลวิเคราะห์เต็ม (projections + summary + risk + input)
            $table->json('chart')->nullable();  // ข้อมูลกราฟการเติบโต
            $table->timestamps();
            $table->index(['user_id', 'stock_id']); // ดึงผลล่าสุดเร็ว
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_analyses');
    }
};
