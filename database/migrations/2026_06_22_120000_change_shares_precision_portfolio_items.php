<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolio_items', function (Blueprint $table) {
            // Dime เก็บเศษหุ้น 7 ตำแหน่ง → เพิ่มความละเอียดจาก 15,4 เป็น 15,7
            $table->decimal('shares', 15, 7)->change();
        });
    }

    public function down(): void
    {
        Schema::table('portfolio_items', function (Blueprint $table) {
            $table->decimal('shares', 15, 4)->change();
        });
    }
};
