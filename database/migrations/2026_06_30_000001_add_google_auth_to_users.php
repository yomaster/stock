<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // ผูกบัญชี Google (opaque sub) — login/เชื่อมบัญชีได้โดยไม่ต้องเก็บอีเมล
            $table->string('google_id')->nullable()->unique()->after('email');
        });

        // privacy: ไม่บังคับชื่อจริง · Google-only user ไม่มีรหัสผ่าน → ทั้งคู่ nullable
        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->nullable()->change();
            $table->string('password')->nullable()->change();
            $table->string('email')->nullable()->change(); // Google-only user ไม่เก็บอีเมล
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('google_id');
        });
    }
};
