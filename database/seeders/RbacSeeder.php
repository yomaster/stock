<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Database\Seeder;

class RbacSeeder extends Seeder
{
    /**
     * สร้าง super-admin role + บัญชีผู้ดูแลคนแรก
     * รัน: php artisan db:seed --class=RbacSeeder
     *
     * ⚠️ เปลี่ยนอีเมล/รหัสผ่านทันทีหลัง login ครั้งแรก
     */
    public function run(): void
    {
        $superRole = Role::firstOrCreate(
            ['name' => 'ผู้ดูแลระบบสูงสุด'],
            [
                'description'  => 'เข้าถึงทุกเมนู — แก้ไข/ลบไม่ได้',
                'permissions'  => Role::validPermissionKeys(),
                'is_super'     => true,
                'is_protected' => true,
            ]
        );

        $admin = User::firstOrCreate(
            ['email' => 'admin@stock.local'],
            [
                'name'     => 'ผู้ดูแลระบบ',
                'password' => 'password',          // cast 'hashed' จะ hash ให้อัตโนมัติ
                'role_id'  => $superRole->id,
                'status'   => true,
            ]
        );

        // backfill: หุ้น/พอร์ตเดิม (ก่อนมีระบบ user) ถูก migrate ไปให้ admin คนแรกแล้ว
        // ที่นี่กันเหนียว — attach หุ้นที่ยังไม่ผูกใครให้ admin (เฉพาะหลังมี pivot จากเฟส 2)
        if (\Illuminate\Support\Facades\Schema::hasTable('user_stocks')) {
            $orphanStockIds = Stock::whereDoesntHave('users')->pluck('id');
            if ($orphanStockIds->isNotEmpty()) {
                $admin->stocks()->syncWithoutDetaching($orphanStockIds);
            }
        }

        $this->command->info("Super admin: admin@stock.local / password (เปลี่ยนรหัสทันที!)");
    }
}
