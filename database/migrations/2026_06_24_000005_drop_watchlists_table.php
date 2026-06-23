<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ลบตาราง watchlists — dead code (ไม่ถูกใช้ที่ไหน)
 * แนวคิด "หุ้นที่ติดตาม" ย้ายไปใช้ pivot user_stocks แทน
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('watchlists');
    }

    public function down(): void
    {
        // คืนโครงเดิม (เผื่อ rollback) — ตาม migration ต้นฉบับ
        Schema::create('watchlists', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
        });
    }
};
