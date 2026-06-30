<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class GoogleAuthController extends Controller
{
    public function __construct(private SettingsService $settings) {}

    /** ส่งไป Google OAuth — ใช้ทั้ง login (guest) และเชื่อมบัญชี (auth) */
    public function redirect()
    {
        if (!$this->configure()) {
            return redirect()->route('login')->withErrors(['email' => 'ระบบยังไม่ได้ตั้งค่า Google login — ติดต่อผู้ดูแล']);
        }
        return Socialite::driver('google')->redirect();
    }

    /** callback จาก Google — แยกกรณี: เชื่อมบัญชี / login / สมัครใหม่ */
    public function callback(Request $request)
    {
        if (!$this->configure()) {
            return redirect()->route('login')->withErrors(['email' => 'ระบบยังไม่ได้ตั้งค่า Google login']);
        }

        try {
            $gUser = Socialite::driver('google')->user();
        } catch (\Throwable $e) {
            return redirect()->route('login')->withErrors(['email' => 'เชื่อมต่อ Google ไม่สำเร็จ ลองใหม่อีกครั้ง']);
        }

        $googleId = $gUser->getId();
        $email    = $gUser->getEmail();

        // ── กรณีล็อกอินอยู่แล้ว = "เชื่อมบัญชี" จากหน้าโปรไฟล์ ──
        if (Auth::check()) {
            $current = Auth::user();
            // กัน google_id นี้ถูกผูกกับบัญชีอื่น
            if (User::where('google_id', $googleId)->where('id', '!=', $current->id)->exists()) {
                return redirect()->route('profile.index')->with('error', 'บัญชี Google นี้ถูกผูกกับผู้ใช้อื่นแล้ว');
            }
            $current->update(['google_id' => $googleId]);
            return redirect()->route('profile.index')->with('success', 'เชื่อมต่อ Google สำเร็จ — ครั้งหน้าล็อกอินด้วย Google ได้เลย');
        }

        // ── login / สมัครใหม่ ──
        // 1) มี google_id นี้แล้ว → บัญชีนั้น
        $user = User::where('google_id', $googleId)->first();

        // 2) auto-link: อีเมล Google ตรงกับบัญชีเดิม (ที่เคยสมัครด้วย email) → ผูกให้ ไม่สร้างใหม่
        if (!$user && $email) {
            $user = User::where('email', $email)->first();
            if ($user) {
                $user->update(['google_id' => $googleId]);
            }
        }

        // 3) สมัครใหม่ (privacy: เก็บแค่ google_id + ชื่อเล่น ไม่เก็บ email/ชื่อจริง)
        if (!$user) {
            $user = User::create([
                'google_id' => $googleId,
                'nickname'  => $this->suggestNickname($gUser, $email),
                'role_id'   => $this->roleForNewUser(),
                'status'    => 1,
            ]);
        }

        if (!$user->status) {
            return redirect()->route('login')->withErrors(['email' => 'บัญชีนี้ถูกปิดใช้งาน — ติดต่อผู้ดูแลระบบ']);
        }

        Auth::login($user, true);
        $request->session()->regenerate();
        return redirect()->route('dashboard');
    }

    /** ยกเลิกการเชื่อม Google (ต้องมีรหัสผ่าน/อีเมล ไว้ล็อกอิน ไม่งั้นจะล็อกตัวเองออก) */
    public function disconnect(Request $request)
    {
        $user = $request->user();
        if (!$user->password || !$user->email) {
            return back()->with('error', 'ตั้งอีเมล + รหัสผ่านก่อน จึงจะยกเลิกการเชื่อม Google ได้ (ไม่งั้นจะล็อกอินไม่ได้)');
        }
        $user->update(['google_id' => null]);
        return back()->with('success', 'ยกเลิกการเชื่อม Google แล้ว');
    }

    // ───────────────────────── helpers ─────────────────────────

    /** ตั้งค่า Socialite จาก Settings DB — คืน false ถ้ายังไม่ได้ตั้ง key */
    private function configure(): bool
    {
        $id     = $this->settings->get('google.client_id');
        $secret = $this->settings->get('google.client_secret');
        if (!$id || !$secret) {
            return false;
        }
        config(['services.google' => [
            'client_id'     => $id,
            'client_secret' => $secret,
            'redirect'      => route('auth.google.callback'),
        ]]);
        return true;
    }

    private function suggestNickname($gUser, ?string $email): string
    {
        return $gUser->getNickname()
            ?: ($email ? Str::before($email, '@') : null)
            ?: ('user' . Str::random(5));
    }

    /** บทบาทสำหรับ user ใหม่: user แรกของระบบ = super, ที่เหลือ = สมาชิก (สิทธิ์พื้นฐาน) */
    private function roleForNewUser(): int
    {
        if (User::count() === 0) {
            $role = Role::where('is_super', true)->first()
                ?? Role::create(['name' => 'ผู้ดูแลระบบ', 'permissions' => Role::validPermissionKeys(), 'is_super' => true]);
            return $role->id;
        }
        $role = Role::firstOrCreate(
            ['name' => 'สมาชิก'],
            ['permissions' => ['dashboard', 'stocks', 'compare', 'manage', 'portfolio'], 'is_super' => false]
        );
        return $role->id;
    }
}
