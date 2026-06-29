<?php

use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\CompareController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LineWebhookController;
use App\Http\Controllers\PortfolioController;
use App\Http\Controllers\PortfolioImportController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\FundManageController;
use App\Http\Controllers\StockManageController;
use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

// Webhook ของ bot (ยกเว้น CSRF ใน bootstrap/app.php — ตรวจ signature/secret เองใน controller) — เปิด public
Route::post('/webhook/line', [LineWebhookController::class, 'handle'])->name('webhook.line');
Route::post('/webhook/telegram', [TelegramWebhookController::class, 'handle'])->name('webhook.telegram');

// ───────────────────────── Auth (guest) ─────────────────────────
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'showLogin'])->name('login');
    Route::post('/login', [LoginController::class, 'login']);

    // ลืมรหัสผ่าน (ส่งลิงก์ทางอีเมล)
    Route::get('/forgot-password', [ForgotPasswordController::class, 'showRequest'])->name('password.request');
    Route::post('/forgot-password', [ForgotPasswordController::class, 'sendLink'])->name('password.email');
    Route::get('/reset-password/{token}', [ResetPasswordController::class, 'showReset'])->name('password.reset');
    Route::post('/reset-password', [ResetPasswordController::class, 'reset'])->name('password.update');
});

Route::post('/logout', [LoginController::class, 'logout'])->middleware('auth')->name('logout');

// ───────────────────────── App (auth required) ─────────────────────────
Route::middleware('auth')->group(function () {

    Route::get('/', [DashboardController::class, 'index'])
        ->middleware('permission:dashboard')->name('dashboard');

    // โปรไฟล์ส่วนตัว (ผูก LINE + ตั้งค่าแจ้งเตือน) — ทุก user เข้าได้ ไม่ต้องมี permission
    Route::prefix('profile')->name('profile.')->group(function () {
        Route::get('/', [ProfileController::class, 'index'])->name('index');
        Route::put('/', [ProfileController::class, 'update'])->name('update');
        Route::put('/password', [ProfileController::class, 'updatePassword'])->name('password');
        Route::post('/line-code', [ProfileController::class, 'generateLineCode'])->name('line.code');
        Route::delete('/line', [ProfileController::class, 'unlinkLine'])->name('line.unlink');
        Route::put('/alerts', [ProfileController::class, 'updateAlerts'])->name('alerts');
    });

    // ตั้งค่าระบบ (API keys, ตารางเวลา)
    Route::middleware('permission:settings')->group(function () {
        Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
        Route::post('/settings', [SettingsController::class, 'update'])->name('settings.update');
    });

    // จัดการผู้ใช้ + role
    Route::middleware('permission:users')->prefix('admin')->name('admin.')->group(function () {
        Route::resource('users', UserController::class)->except(['show']);
        Route::put('users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');
        Route::resource('roles', RoleController::class)->except(['show']);
    });

    // พอร์ตการลงทุน + AI health check
    Route::middleware('permission:portfolio')->prefix('portfolio')->name('portfolio.')->group(function () {
        Route::get('/', [PortfolioController::class, 'index'])->name('index');
        Route::get('/holdings', [PortfolioController::class, 'holdings'])->name('holdings');
        Route::post('/items', [PortfolioController::class, 'storeItem'])->name('items.store');

        // นำเข้าจากภาพหน้าจอโบรก (Gemini Vision)
        Route::post('/import/parse', [PortfolioImportController::class, 'parse'])->name('import.parse');
        Route::post('/import/confirm', [PortfolioImportController::class, 'confirm'])->name('import.confirm');
        Route::put('/items/{item}', [PortfolioController::class, 'updateItem'])->name('items.update');
        Route::delete('/items/{item}', [PortfolioController::class, 'destroyItem'])->name('items.destroy');
        Route::post('/health-check', [PortfolioController::class, 'healthCheck'])->name('health');

        // จัดการพอร์ต (หลายพอร์ต)
        Route::post('/portfolios', [PortfolioController::class, 'storePortfolio'])->name('portfolios.store');
        Route::put('/portfolios/{portfolio}', [PortfolioController::class, 'renamePortfolio'])->name('portfolios.rename');
        Route::get('/portfolios/{portfolio}/switch', [PortfolioController::class, 'switchPortfolio'])->name('portfolios.switch');
        Route::delete('/portfolios/{portfolio}', [PortfolioController::class, 'destroyPortfolio'])->name('portfolios.destroy');
    });

    // Stock management (เพิ่ม/ลบ/รีเฟรชหุ้น)
    Route::middleware('permission:manage')->prefix('manage')->name('manage.')->group(function () {
        Route::get('/', [StockManageController::class, 'index'])->name('index');
        Route::post('/', [StockManageController::class, 'store'])->name('store');
        Route::post('/{stock}/refresh', [StockManageController::class, 'refresh'])->name('refresh');
        Route::delete('/{stock}', [StockManageController::class, 'destroy'])->name('destroy');
    });

    // Fund management (เพิ่ม/ลบกองทุนรวมไทย — NAV จาก SEC API)
    Route::middleware('permission:manage')->prefix('funds')->name('funds.')->group(function () {
        Route::get('/search', [FundManageController::class, 'search'])->name('search');   // AJAX autocomplete
        Route::post('/', [FundManageController::class, 'store'])->name('store');
        Route::delete('/{stock}', [FundManageController::class, 'destroy'])->name('destroy');
    });

    // Asset analysis (เดิม /stocks — เปลี่ยนเป็น /asset ให้ครอบคลุมทุกชนิดสินทรัพย์)
    // ⚠️ ใช้ prefix เอกพจน์ 'asset' เพราะ /assets ชนกับโฟลเดอร์จริง public/assets/
    //    (web server เสิร์ฟ static dir ก่อนเข้า Laravel → /assets 404)
    //    route name ยังเป็น 'assets.*' (plural) — ไม่ต้องแก้ route() ที่อื่น
    // หมายเหตุ: permission slug ยังเป็น 'stocks'/'compare' (ไม่แตะ role ใน DB)
    Route::prefix('asset')->name('assets.')->group(function () {
        Route::get('/', [StockController::class, 'index'])->middleware('permission:stocks')->name('index');
        Route::get('/compare', [CompareController::class, 'index'])->middleware('permission:compare')->name('compare');

        // หน้าวิเคราะห์รายสินทรัพย์ — ต้องมีสิทธิ์ stocks
        Route::middleware('permission:stocks')->group(function () {
            Route::get('/{stock}', [StockController::class, 'show'])->name('show');
            Route::get('/{stock}/backtest', [StockController::class, 'backtestForm'])->name('backtest');
            Route::post('/{stock}/backtest', [StockController::class, 'backtestRun'])->name('backtest.run');
            Route::get('/{stock}/analyze', [StockController::class, 'analyzeForm'])->name('analyze');
            Route::post('/{stock}/analyze', [StockController::class, 'analyzeRun'])->name('analyze.run');
        });
    });

    // backward-compat: ลิงก์เก่า /stocks/* → /asset/* (กัน bookmark พัง)
    Route::redirect('/stocks', '/asset');
    Route::get('/stocks/{path}', fn (string $path) => redirect('/asset/' . $path))->where('path', '.*');
});
