<?php

namespace App\Http\Controllers;

use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class ProfileController extends Controller
{
    public function index(Request $request, SettingsService $settings)
    {
        return view('profile.index', [
            'user'        => $request->user(),
            'provider'    => $settings->get('messaging.provider', 'line'),
            'telegramBot' => $settings->get('telegram.bot_username'),
        ]);
    }

    /** แก้ชื่อเล่น/อีเมล (privacy: ไม่เก็บชื่อจริง — email optional สำหรับ user ที่ login ด้วย Google) */
    public function update(Request $request)
    {
        $user = $request->user();
        $validated = $request->validate([
            'nickname' => 'required|string|max:50',
            'email'    => ['nullable', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
        ]);

        $user->update([
            'nickname' => $validated['nickname'],
            'email'    => $validated['email'] ?: null,
        ]);
        return back()->with('success', 'บันทึกข้อมูลโปรไฟล์แล้ว');
    }

    /**
     * ลบอีเมล + รหัสผ่านออก (ทำได้เฉพาะเมื่อเชื่อม Google แล้ว — เพื่อความเป็นส่วนตัว → Google-only)
     * กันล็อกเอาต์: ต้องมี google_id อยู่ก่อน
     */
    public function removeEmail(Request $request)
    {
        $user = $request->user();
        if (!$user->google_id) {
            return back()->with('error', 'ต้องเชื่อมต่อ Google ก่อน จึงจะลบอีเมลได้ (ไม่งั้นจะล็อกอินไม่ได้)');
        }
        $user->update(['email' => null, 'password' => null]);
        return back()->with('success', 'ลบอีเมลและรหัสผ่านแล้ว — จากนี้ล็อกอินด้วย Google เท่านั้น');
    }

    /** เปลี่ยน/ตั้งรหัสผ่าน — Google-only user ที่ยังไม่มีรหัส ไม่ต้องยืนยันรหัสเดิม */
    public function updatePassword(Request $request)
    {
        $hasPassword = (bool) $request->user()->password;

        $validated = $request->validate([
            'current_password' => [$hasPassword ? 'required' : 'nullable', 'current_password'],
            'password'         => ['required', 'confirmed', Password::min(8)],
        ]);

        $request->user()->update(['password' => $validated['password']]); // cast 'hashed'
        return back()->with('success', $hasPassword ? 'เปลี่ยนรหัสผ่านแล้ว' : 'ตั้งรหัสผ่านแล้ว — ใช้อีเมล + รหัสผ่านล็อกอินได้');
    }

    /** สร้างรหัสผูกบัญชี LINE (6 หลัก หมดอายุ 10 นาที) */
    public function generateLineCode(Request $request)
    {
        $code = strtoupper(Str::random(6));
        $request->user()->forceFill([
            'messaging_link_code'            => $code,
            'messaging_link_code_expires_at' => now()->addMinutes(10),
        ])->save();

        return back()->with('success', "รหัสผูกบัญชี: {$code} — พิมพ์ /link {$code} ใน LINE ภายใน 10 นาที");
    }

    /** ยกเลิกการผูก LINE */
    public function unlinkLine(Request $request)
    {
        $request->user()->forceFill([
            'messaging_chat_id'              => null,
            'messaging_provider'             => null,
            'messaging_link_code'            => null,
            'messaging_link_code_expires_at' => null,
        ])->save();

        return back()->with('success', 'ยกเลิกการผูกบัญชีแล้ว');
    }

    /** ตั้งค่าการแจ้งเตือนรายคน */
    public function updateAlerts(Request $request)
    {
        $validated = $request->validate([
            'alert_price_threshold'   => 'required|numeric|min:0.5|max:50',
            'alert_volume_multiplier' => 'required|numeric|min:1|max:20',
        ]);

        $request->user()->update([
            'alert_enabled'           => $request->boolean('alert_enabled'),
            'summary_enabled'         => $request->boolean('summary_enabled'),
            'alert_price_threshold'   => $validated['alert_price_threshold'],
            'alert_volume_multiplier' => $validated['alert_volume_multiplier'],
        ]);

        return back()->with('success', 'บันทึกการตั้งค่าแจ้งเตือนแล้ว');
    }
}
