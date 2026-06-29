<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catalog กองทุนทั้งหมดจาก SEC (sync จาก /v2/fund/general-info/profiles)
     * SEC ไม่มี search-by-name endpoint → ดึง catalog มาเก็บไว้ค้นในเครื่อง (autocomplete)
     * แยกจาก stocks: stocks = สินทรัพย์ที่ user ติดตามจริง, sec_funds = รายการให้เลือกเพิ่ม
     */
    public function up(): void
    {
        Schema::create('sec_funds', function (Blueprint $table) {
            $table->id();
            $table->string('proj_id')->unique();          // รหัสโครงการ เช่น M0002_2545
            $table->string('proj_abbr_name')->nullable()->index(); // ชื่อย่อ เช่น ES-TM
            $table->string('proj_name_th')->nullable();
            $table->string('amc_name_th')->nullable();
            $table->string('fund_status')->nullable();     // Registered / Cancelled ฯลฯ
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sec_funds');
    }
};
