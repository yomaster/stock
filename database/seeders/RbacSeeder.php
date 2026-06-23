<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Database\Seeder;

class RbacSeeder extends Seeder
{
    /**
     * สร้าง super-admin role + บัญชีผู้ดูแลคนแรก (idempotent — รันซ้ำใน deploy script ได้)
     * รัน: php artisan db:seed --class=RbacSeeder
     *
     * ⚠️ เปลี่ยนอีเมล/รหัสผ่านทันทีหลัง login ครั้งแรก
     */
    public function run(): void
    {
        // role ใช้ firstOrCreate ตามชื่อ — ปลอดภัยที่จะรันซ้ำทุก deploy
        $superRole = Role::firstOrCreate(
            ['name' => 'ผู้ดูแลระบบสูงสุด'],
            [
                'description'  => 'เข้าถึงทุกเมนู — แก้ไข/ลบไม่ได้',
                'permissions'  => Role::validPermissionKeys(),
                'is_super'     => true,
                'is_protected' => true,
            ]
        );

        // ⚠️ สร้าง admin "เฉพาะตอนระบบยังไม่มี user เลย" (ครั้งแรกเท่านั้น)
        //    กัน backdoor: ถ้า admin เปลี่ยนอีเมลตัวเองแล้ว deploy รอบหน้าจะ "ไม่" สร้าง
        //    admin@stock.local/password ขึ้นมาใหม่ — เพราะมี user อยู่แล้ว
        if (User::count() > 0) {
            $this->command->info('มีผู้ใช้ในระบบแล้ว — ข้ามการสร้าง admin เริ่มต้น');
            return;
        }

        $admin = User::create([
            'email'    => 'admin@stock.local',
            'name'     => 'ผู้ดูแลระบบ',
            'password' => 'password',          // cast 'hashed' จะ hash ให้อัตโนมัติ
            'role_id'  => $superRole->id,
            'status'   => true,
        ]);

        // backfill: หุ้นเดิม (ก่อนมีระบบ user) ที่ยังไม่ผูกใคร → ผูกให้ admin คนแรก
        if (\Illuminate\Support\Facades\Schema::hasTable('user_stocks')) {
            $orphanStockIds = Stock::whereDoesntHave('users')->pluck('id');
            if ($orphanStockIds->isNotEmpty()) {
                $admin->stocks()->syncWithoutDetaching($orphanStockIds);
            }
        }

        $this->command->info('Super admin: admin@stock.local / password (เปลี่ยนรหัสทันที!)');
    }
}
