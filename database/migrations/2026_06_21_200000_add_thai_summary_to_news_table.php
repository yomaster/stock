<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news', function (Blueprint $table) {
            // หัวข้อข่าว + สรุปภาษาไทยที่ AI แปล/ย่อให้ (null = ยังไม่ได้แปล)
            $table->text('title_th')->nullable()->after('title');
            $table->text('summary_th')->nullable()->after('summary');
        });
    }

    public function down(): void
    {
        Schema::table('news', function (Blueprint $table) {
            $table->dropColumn(['title_th', 'summary_th']);
        });
    }
};
