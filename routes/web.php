<?php

use App\Http\Controllers\CompareController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LineWebhookController;
use App\Http\Controllers\PortfolioController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\StockManageController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

// LINE webhook (ยกเว้น CSRF ใน bootstrap/app.php — ตรวจ signature เองใน controller)
Route::post('/webhook/line', [LineWebhookController::class, 'handle'])->name('webhook.line');

// ตั้งค่าระบบ (API keys, ตารางเวลา, ฯลฯ)
Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
Route::post('/settings', [SettingsController::class, 'update'])->name('settings.update');

// พอร์ตการลงทุน + AI health check
Route::prefix('portfolio')->name('portfolio.')->group(function () {
    Route::get('/', [PortfolioController::class, 'index'])->name('index');
    Route::get('/holdings', [PortfolioController::class, 'holdings'])->name('holdings');
    Route::post('/items', [PortfolioController::class, 'storeItem'])->name('items.store');
    Route::delete('/items/{item}', [PortfolioController::class, 'destroyItem'])->name('items.destroy');
    Route::post('/health-check', [PortfolioController::class, 'healthCheck'])->name('health');
});

// Stock management (เพิ่ม/ลบ/รีเฟรชหุ้น)
Route::prefix('manage')->name('manage.')->group(function () {
    Route::get('/', [StockManageController::class, 'index'])->name('index');
    Route::post('/', [StockManageController::class, 'store'])->name('store');
    Route::post('/{stock}/refresh', [StockManageController::class, 'refresh'])->name('refresh');
    Route::delete('/{stock}', [StockManageController::class, 'destroy'])->name('destroy');
});

// Stock analysis
Route::prefix('stocks')->name('stocks.')->group(function () {
    Route::get('/', [StockController::class, 'index'])->name('index');
    Route::get('/compare', [CompareController::class, 'index'])->name('compare');
    Route::get('/{stock}', [StockController::class, 'show'])->name('show');

    // DCA Backtest
    Route::get('/{stock}/backtest', [StockController::class, 'backtestForm'])->name('backtest');
    Route::post('/{stock}/backtest', [StockController::class, 'backtestRun'])->name('backtest.run');

    // AI Projection
    Route::get('/{stock}/analyze', [StockController::class, 'analyzeForm'])->name('analyze');
    Route::post('/{stock}/analyze', [StockController::class, 'analyzeRun'])->name('analyze.run');
});
