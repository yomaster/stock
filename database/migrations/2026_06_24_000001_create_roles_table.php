<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('description')->nullable();
            $table->json('permissions')->nullable(); // array ของ menu_group strings
            $table->boolean('is_super')->default(false);     // true = bypass ทุกสิทธิ์
            $table->boolean('is_protected')->default(false); // true = ห้าม UI ลบ/แก้ (กัน lock-self)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
