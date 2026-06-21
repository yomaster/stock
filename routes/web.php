<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\StockManageController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

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
    Route::get('/{stock}', [StockController::class, 'show'])->name('show');

    // DCA Backtest
    Route::get('/{stock}/backtest', [StockController::class, 'backtestForm'])->name('backtest');
    Route::post('/{stock}/backtest', [StockController::class, 'backtestRun'])->name('backtest.run');

    // AI Projection
    Route::get('/{stock}/analyze', [StockController::class, 'analyzeForm'])->name('analyze');
    Route::post('/{stock}/analyze', [StockController::class, 'analyzeRun'])->name('analyze.run');
});
