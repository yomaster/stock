<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ป้ายประเภทพอร์ต (optional) — ไว้จัดระเบียบ/แสดง badge เท่านั้น
     * ไม่บังคับชนิดสินทรัพย์ที่ถือได้ (พอร์ตยังถือผสมได้)
     * ค่า: stock | fund | gold | mixed | null (ไม่ระบุ)
     */
    public function up(): void
    {
        Schema::table('portfolios', function (Blueprint $table) {
            $table->string('category')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('portfolios', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
};
