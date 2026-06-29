<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * note: บันทึกที่มาของรายการ — ใช้กับ "สับเปลี่ยน" (switch) ที่แตกเป็น ขาย+ซื้อ
     * เช่น "สับเปลี่ยนจาก ONE-UGG-ASSF" / "สับเปลี่ยนไป ONE-TCMSSF-SSF"
     */
    public function up(): void
    {
        Schema::table('portfolio_items', function (Blueprint $table) {
            $table->string('note')->nullable()->after('executed_at');
        });
    }

    public function down(): void
    {
        Schema::table('portfolio_items', function (Blueprint $table) {
            $table->dropColumn('note');
        });
    }
};
