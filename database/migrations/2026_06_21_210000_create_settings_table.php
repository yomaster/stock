<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();          // เช่น gemini.api_key
            $table->text('value')->nullable();        // ค่า secret จะถูกเข้ารหัสก่อนเก็บ
            $table->string('group')->default('general'); // จัดกลุ่มในหน้า UI
            $table->boolean('is_secret')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
