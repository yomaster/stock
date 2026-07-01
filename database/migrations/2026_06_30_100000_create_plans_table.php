<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * แผน DCA (Phase 2)
 * แนวคิด: ดึง "สินทรัพย์ในพอร์ต" มาเป็นมูลค่าตั้งต้นรายตัว แล้วกำหนดยอด DCA แยกรายสินทรัพย์
 * - asset_dca   = ยอด DCA ต่อครั้ง แยกรายสินทรัพย์ { "AAPL": 3000, "PTT.BK": 2000 } (THB)
 * - frequency   = ความถี่ (daily/weekly/monthly/custom/once); custom = เลือกวันที่ของเดือนเอง
 * - frequency_days = วันที่ของเดือนสำหรับ custom เช่น [4, 20]
 * - start_date  = วันเริ่ม DCA
 * - result/ai_analysis = ผลคำนวณ (สูตร) + บทวิเคราะห์ AI ที่ cache ไว้
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('portfolio_id')->constrained()->onDelete('cascade');
            $table->string('name', 100);
            $table->unsignedTinyInteger('current_age')->nullable(); // อายุปัจจุบัน (คำนวณปีเกษียณ + แสดงผล)
            $table->unsignedTinyInteger('retire_age')->nullable();  // อายุที่ตั้งใจเกษียณ
            $table->unsignedSmallInteger('years');                  // จำนวนปีที่จะ DCA (horizon ที่ใช้คำนวณ)
            $table->date('start_date');                             // วันเริ่ม DCA
            $table->enum('frequency', ['daily', 'weekly', 'monthly', 'custom', 'once'])->default('monthly');
            $table->json('frequency_days')->nullable();             // วันที่ของเดือน (เฉพาะ custom) เช่น [4,20]
            $table->json('asset_dca')->nullable();                  // ยอด DCA ต่อครั้ง แยกรายสินทรัพย์ (symbol => บาท)
            $table->json('asset_cagr')->nullable();                 // CAGR คาดหวัง/ปี ที่ผู้ใช้ปรับเอง (symbol => %) — ว่าง = ใช้ค่า cap อัตโนมัติ
            $table->json('asset_excluded')->nullable();             // สินทรัพย์ที่ผู้ใช้เอาออกจากแผน (list ของ symbol) — ไม่นำมาคำนวณ
            $table->json('result')->nullable();                     // ผล projection (สูตร) รายสินทรัพย์ + รวม
            $table->longText('ai_analysis')->nullable();            // บทวิเคราะห์ AI (markdown) — ตีความ ไม่ได้คำนวณ
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
