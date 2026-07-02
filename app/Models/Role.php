<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Role (RBAC) — กำหนดสิทธิ์การเข้าถึงเมนูระดับกลุ่ม (mirror จาก dmsnew)
 *
 * - permissions: array ของ menu_group strings
 * - is_super:    true = bypass ทุกสิทธิ์ (canAccessMenuGroup() คืน true เสมอ)
 * - is_protected: true = ห้าม UI ลบ/แก้ permissions (กัน lock-self)
 */
class Role extends Model
{
    use HasFactory;

    /**
     * Menu groups ทั้งหมดของระบบ — central definition
     * - key   = permission string ที่ใช้ใน middleware('permission:<key>') + permissions[]
     * - label = ชื่อแสดงผลภาษาไทย
     * - group = หมวดใหญ่ใน UI (จัด layout checkbox)
     */
    public const MENU_GROUPS = [
        'dashboard' => ['label' => 'แดชบอร์ด',     'group' => 'หลัก'],
        'stocks'    => ['label' => 'สินทรัพย์ทั้งหมด',   'group' => 'หุ้น'],
        'compare'   => ['label' => 'เปรียบเทียบ',   'group' => 'หุ้น'],
        'manage'    => ['label' => 'จัดการสินทรัพย์', 'group' => 'หุ้น'],
        'portfolio' => ['label' => 'พอร์ตการลงทุน', 'group' => 'หุ้น'],
        'overview'  => ['label' => 'พอร์ตรวม',      'group' => 'หุ้น'],
        'plan'      => ['label' => 'แผน DCA',       'group' => 'หุ้น'],
        'report'    => ['label' => 'รายงานภาษี',    'group' => 'หุ้น'],
        'users'     => ['label' => 'จัดการผู้ใช้',   'group' => 'ระบบ'],
        'settings'  => ['label' => 'ตั้งค่าระบบ',    'group' => 'ระบบ'],
    ];

    protected $fillable = [
        'name', 'description', 'permissions',
        'is_super', 'is_protected',
    ];

    protected function casts(): array
    {
        return [
            'permissions'  => 'array',
            'is_super'     => 'boolean',
            'is_protected' => 'boolean',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** เช็คว่า role นี้เข้ากลุ่มเมนูนี้ได้ไหม (super role ผ่านทุกอย่าง) */
    public function canAccessMenuGroup(string $group): bool
    {
        if ($this->is_super) {
            return true;
        }
        return in_array($group, $this->permissions ?? [], true);
    }

    /** valid permission keys (สำหรับ validation) */
    public static function validPermissionKeys(): array
    {
        return array_keys(self::MENU_GROUPS);
    }

    /** จัดกลุ่ม menu groups ตาม 'group' สำหรับแสดง UI */
    public static function groupedMenuGroups(): array
    {
        $grouped = [];
        foreach (self::MENU_GROUPS as $key => $meta) {
            $grouped[$meta['group']][$key] = $meta;
        }
        return $grouped;
    }
}
