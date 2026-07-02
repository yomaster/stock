<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * เพิ่มสิทธิ์ 'overview' (พอร์ตรวม) + 'report' (รายงานภาษี) เข้า role ที่มีอยู่
 * เดิม 2 เมนูนี้ gate ด้วย 'portfolio' → พอแยกเป็นสิทธิ์ของตัวเอง ต้อง backfill
 * ให้ role ที่มี 'portfolio' อยู่ได้ทั้งคู่ด้วย จะได้ไม่หลุดสิทธิ์ (super bypass อยู่แล้ว แต่เติมให้ครบ)
 */
return new class extends Migration
{
    private array $add = ['overview', 'report'];

    public function up(): void
    {
        foreach (DB::table('roles')->get(['id', 'permissions', 'is_super']) as $role) {
            $perms = json_decode($role->permissions ?? '[]', true) ?: [];
            $shouldHave = $role->is_super || in_array('portfolio', $perms, true);
            if (!$shouldHave) {
                continue;
            }
            $changed = false;
            foreach ($this->add as $p) {
                if (!in_array($p, $perms, true)) {
                    $perms[] = $p;
                    $changed = true;
                }
            }
            if ($changed) {
                DB::table('roles')->where('id', $role->id)
                    ->update(['permissions' => json_encode(array_values($perms))]);
            }
        }
    }

    public function down(): void
    {
        foreach (DB::table('roles')->get(['id', 'permissions']) as $role) {
            $perms = json_decode($role->permissions ?? '[]', true) ?: [];
            $perms = array_values(array_filter($perms, fn ($p) => !in_array($p, $this->add, true)));
            DB::table('roles')->where('id', $role->id)
                ->update(['permissions' => json_encode($perms)]);
        }
    }
};
