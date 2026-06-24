<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Generalize การผูกบัญชี messaging: line_* → messaging_* + เพิ่ม messaging_provider
 * รองรับหลาย provider (LINE/Telegram) โดยเลือกใช้ทีละตัว
 */
return new class extends Migration
{
    public function up(): void
    {
        // rename คอลัมน์เดิม (Laravel 13 รองรับ renameColumn native)
        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('line_user_id', 'messaging_chat_id');
            $table->renameColumn('line_link_code', 'messaging_link_code');
            $table->renameColumn('line_link_code_expires_at', 'messaging_link_code_expires_at');
        });

        // เพิ่ม provider ของ binding (line|telegram) — แยก call กัน DB บางตัว conflict
        Schema::table('users', function (Blueprint $table) {
            $table->string('messaging_provider')->nullable()->after('avatar');
        });

        // backfill: binding เดิมทั้งหมดเป็นของ LINE
        DB::table('users')->whereNotNull('messaging_chat_id')->update(['messaging_provider' => 'line']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('messaging_provider');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('messaging_chat_id', 'line_user_id');
            $table->renameColumn('messaging_link_code', 'line_link_code');
            $table->renameColumn('messaging_link_code_expires_at', 'line_link_code_expires_at');
        });
    }
};
