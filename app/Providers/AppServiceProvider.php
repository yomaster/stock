<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
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
