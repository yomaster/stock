<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ชนิดหน่วยลงทุน (fund_class_name) ที่เลือกเป็น "ตัวแทน" ของกองทุนนี้
     * — NAV endpoint คืนหลาย class ปนกัน + ชื่อ class เพี้ยน (SEC แทน '-' ด้วย 'main')
     *   จึงต้องล็อก 1 class ไว้ตอนเพิ่มกอง แล้วกรองเฉพาะ class นี้ตอนดึง NAV
     */
    public function up(): void
    {
        Schema::table('stocks', function (Blueprint $table) {
            $table->string('sec_nav_class')->nullable()->after('sec_proj_id');
        });
    }

    public function down(): void
    {
        Schema::table('stocks', function (Blueprint $table) {
            $table->dropColumn('sec_nav_class');
        });
    }
};
