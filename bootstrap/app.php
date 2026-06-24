<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // webhook ของ bot ไม่มี CSRF token (มาจาก platform) → ยกเว้น แล้วตรวจ signature/secret เอง
        $middleware->validateCsrfTokens(except: [
            'webhook/line',
            'webhook/telegram',
        ]);

        // RBAC: middleware('permission:<menu_group>') → ตรวจสิทธิ์ผ่าน Role
        $middleware->alias([
            'permission' => \App\Http\Middleware\EnsurePermission::class,
        ]);

        // guest ที่ยังไม่ login → ส่งไปหน้า login
        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
