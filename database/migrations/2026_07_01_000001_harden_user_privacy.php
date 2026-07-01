<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * เพิ่มความเป็นส่วนตัว (privacy hardening)
 * 1) เอา `name` (ชื่อจริง) ออก — ใช้ `nickname` แทนทั้งระบบ
 * 2) เข้ารหัส `google_id` (cast encrypted) + เพิ่ม `google_id_hash` (sha256) เป็น blind index
 *    เพราะ encrypted แบบ non-deterministic ค้นด้วย where ตรงๆ ไม่ได้ → lookup ผ่าน hash แทน
 *    (ย้าย unique จาก google_id → google_id_hash)
 * ⚠️ backfill: แปลง google_id เดิม (plaintext) เป็น hash + ciphertext ให้ครบก่อน cast จะ decrypt ตอนอ่าน
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── google_id: ย้าย unique ไป hash + ขยายคอลัมน์รองรับ ciphertext ──
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['google_id']); // users_google_id_unique
        });
        Schema::table('users', function (Blueprint $table) {
            $table->text('google_id')->nullable()->change();            // ciphertext ยาวกว่า 255 ได้
            $table->string('google_id_hash', 64)->nullable()->after('google_id');
            $table->unique('google_id_hash');
        });

        // backfill: ค่าเดิมเป็น plaintext → hash (ค้นหา) + encrypt (จัดเก็บ)
        DB::table('users')->whereNotNull('google_id')->orderBy('id')
            ->each(function ($row) {
                DB::table('users')->where('id', $row->id)->update([
                    'google_id_hash' => hash('sha256', $row->google_id),
                    'google_id'      => Crypt::encryptString($row->google_id),
                ]);
            });

        // ── เอา name (ชื่อจริง) ออก — privacy ──
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->nullable()->after('id');
        });

        // decrypt google_id กลับเป็น plaintext (best-effort) ก่อนคืน unique เดิม
        DB::table('users')->whereNotNull('google_id')->orderBy('id')
            ->each(function ($row) {
                try {
                    $plain = Crypt::decryptString($row->google_id);
                } catch (\Throwable $e) {
                    $plain = $row->google_id;
                }
                DB::table('users')->where('id', $row->id)->update(['google_id' => $plain]);
            });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['google_id_hash']);
            $table->dropColumn('google_id_hash');
            $table->string('google_id')->nullable()->change();
            $table->unique('google_id');
        });
    }
};
