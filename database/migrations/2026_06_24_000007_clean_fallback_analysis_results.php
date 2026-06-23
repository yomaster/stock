<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ล้างบทประเมินที่เป็นผล fallback (ข้อความ error) ออกจาก analysis_results
 * — เกิดช่วงก่อนแก้ thinking model ที่ AI คืน text ว่างแล้วระบบใช้ค่า default
 * รันครั้งเดียวตอน deploy → หุ้นที่ค้าง error จะหายจาก dashboard จนกว่าจะวิเคราะห์ใหม่
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('analysis_results')
            ->where('summary', 'like', '%ระบุข้อผิดพลาดในการดึงข้อมูลจาก AI%')
            ->delete();
    }

    public function down(): void
    {
        // ลบข้อมูลขยะทิ้ง — กู้คืนไม่ได้ (ไม่จำเป็น)
    }
};
