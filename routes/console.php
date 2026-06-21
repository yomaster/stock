<?php

use App\Services\SettingsService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

$settings = app(SettingsService::class);

// ── ดึงข้อมูลสดทุกเช้า (เวลาไทย) ก่อนสรุป ──
Schedule::command('app:fetch-stock-data')->dailyAt('06:00')->timezone('Asia/Bangkok');
Schedule::command('app:fetch-stock-news')->dailyAt('06:15')->timezone('Asia/Bangkok');
Schedule::command('app:summarize-news')->dailyAt('06:30')->timezone('Asia/Bangkok');

// ── สรุปก่อนตลาดเปิด (เวลาตั้งใน /settings) ──
// หุ้นไทย: เวลาไทย / หุ้น US: เวลา New York (Laravel แปลง DST เป็นเวลาไทยให้เอง)
Schedule::command('app:send-summary --market=TH')
    ->weekdays()->timezone('Asia/Bangkok')
    ->at($settings->get('schedule.th_summary_time', '09:30'));

Schedule::command('app:send-summary --market=US')
    ->weekdays()->timezone('America/New_York')
    ->at($settings->get('schedule.us_summary_time_ny', '09:00'));

// ── ระบาย queue (เช่น /ask) ทุกนาที — ไม่ต้องมี worker ค้างตลอด ──
Schedule::command('queue:work --stop-when-empty --tries=1 --max-time=55')
    ->everyMinute()->withoutOverlapping();
