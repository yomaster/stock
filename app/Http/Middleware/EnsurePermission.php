<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * ตรวจสิทธิ์ตามกลุ่มเมนู (menu group) — RBAC ผ่าน Role model
 *
 * ใช้งาน: middleware('permission:portfolio') → role ต้องมี 'portfolio' ใน permissions
 * Role ที่ is_super=true → bypass ทุกกลุ่ม
 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $group): Response
    {
        $user = Auth::user();

        if (!$user) {
            return redirect()->route('login');
        }

        if (!method_exists($user, 'canAccessMenuGroup') || !$user->canAccessMenuGroup($group)) {
            abort(403, 'คุณไม่มีสิทธิ์เข้าถึงเมนูนี้');
        }

        return $next($request);
    }
}
