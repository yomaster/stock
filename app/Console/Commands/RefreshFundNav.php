<?php

namespace App\Console\Commands;

use App\Jobs\FetchFundNavJob;
use App\Models\Stock;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:refresh-fund-nav {--days=10 : ดึง NAV ย้อนหลังกี่วัน}')]
#[Description('อัปเดต NAV ล่าสุดของกองทุนที่ติดตาม (dispatch FetchFundNavJob ต่อกอง)')]
class RefreshFundNav extends Command
{
    public function handle(): int
    {
        $from = now()->subDays((int) $this->option('days'))->format('Y-m-d');

        // เฉพาะกองทุนที่มี proj_id + class ตัวแทนแล้ว (เพิ่มผ่านระบบสำเร็จ)
        $funds = Stock::where('asset_category', 'fund')
            ->whereNotNull('sec_proj_id')
            ->whereNotNull('sec_nav_class')
            ->get(['id', 'sec_proj_id', 'sec_nav_class']);

        foreach ($funds as $f) {
            FetchFundNavJob::dispatch($f->id, $f->sec_proj_id, $f->sec_nav_class, $from);
        }

        $this->info("dispatch refresh NAV {$funds->count()} กองทุน (ย้อนหลังจาก {$from})");
        return self::SUCCESS;
    }
}
