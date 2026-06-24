<?php

namespace App\Providers;

use App\Services\Messaging\LineMessagingService;
use App\Services\Messaging\MessagingService;
use App\Services\Messaging\TelegramMessagingService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // resolve messaging provider ที่ active ตาม setting (admin เลือก line|telegram)
        // ใช้กับงาน proactive (สรุป/alert) + หน้าโปรไฟล์; webhook ของแต่ละ provider ใช้ service ตรงตัวเอง
        $this->app->bind(MessagingService::class, function ($app) {
            $provider = $app->make(SettingsService::class)->get('messaging.provider', 'line');
            return $provider === 'telegram'
                ? $app->make(TelegramMessagingService::class)
                : $app->make(LineMessagingService::class);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // อยู่หลัง Cloudflare/reverse proxy → บังคับสร้าง URL เป็น https บน production
        // กัน mixed content (asset @vite ออกเป็น http บนหน้า https → "Not secure")
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
