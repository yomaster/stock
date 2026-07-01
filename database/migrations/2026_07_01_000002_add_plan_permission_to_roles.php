<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * เพิ่มสิทธิ์ 'plan' (เมนูแผน DCA) เข้า role ที่มีอยู่แล้ว
 * เดิมเมนูแผน DCA ใช้สิทธิ์ 'portfolio' — พอแยกเป็น 'plan' ต้อง backfill
 * ให้ role ที่มี 'portfolio' อยู่ (เช่น "สมาชิก") ได้ 'plan' ด้วย จะได้ไม่หลุดสิทธิ์
 * (role is_super bypass อยู่แล้ว แต่เติมใน array ให้ครบเพื่อความสม่ำเสมอ)
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('roles')->get(['id', 'permissions', 'is_super']) as $role) {
            $perms = json_decode($role->permissions ?? '[]', true) ?: [];

            $shouldHave = $role->is_super || in_array('portfolio', $perms, true);
            if ($shouldHave && !in_array('plan', $perms, true)) {
                $perms[] = 'plan';
                DB::table('roles')->where('id', $role->id)
                    ->update(['permissions' => json_encode(array_values($perms))]);
            }
        }
    }

    public function down(): void
    {
        foreach (DB::table('roles')->get(['id', 'permissions']) as $role) {
            $perms = json_decode($role->permissions ?? '[]', true) ?: [];
            if (in_array('plan', $perms, true)) {
                $perms = array_values(array_filter($perms, fn ($p) => $p !== 'plan'));
                DB::table('roles')->where('id', $role->id)
                    ->update(['permissions' => json_encode($perms)]);
            }
        }
    }
};
