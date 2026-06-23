<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // ── RBAC ──
            $table->string('nickname')->nullable()->after('name');
            $table->foreignId('role_id')->nullable()->after('email')
                ->constrained()->nullOnDelete();
            $table->boolean('status')->default(true)->after('role_id'); // false = ปิดใช้งาน (login ไม่ได้)
            $table->string('avatar')->nullable()->after('status');

            // ── LINE binding (เฟส 3) ──
            $table->string('line_user_id')->nullable()->unique()->after('avatar');
            $table->string('line_link_code')->nullable()->after('line_user_id');
            $table->timestamp('line_link_code_expires_at')->nullable()->after('line_link_code');

            // ── การตั้งค่าแจ้งเตือนรายคน (ย้ายจาก global settings มาเป็น per-user) ──
            $table->boolean('alert_enabled')->default(true)->after('line_link_code_expires_at');
            $table->decimal('alert_price_threshold', 5, 2)->default(5)->after('alert_enabled');  // % เปลี่ยนแปลงราคา
            $table->decimal('alert_volume_multiplier', 5, 2)->default(2.5)->after('alert_price_threshold'); // เท่าของ avg volume
            $table->boolean('summary_enabled')->default(true)->after('alert_volume_multiplier');  // รับสรุปเช้าไหม
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['role_id']);
            $table->dropColumn([
                'nickname', 'role_id', 'status', 'avatar',
                'line_user_id', 'line_link_code', 'line_link_code_expires_at',
                'alert_enabled', 'alert_price_threshold', 'alert_volume_multiplier', 'summary_enabled',
            ]);
        });
    }
};
