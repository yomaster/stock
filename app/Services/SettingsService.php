<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

class SettingsService
{
    private const CACHE_KEY = 'app_settings';

    /**
     * Registry ของ setting ทั้งหมด — เป็น source เดียวที่ใช้ทั้งอ่านค่า และสร้างหน้า UI
     * - secret: true → เข้ารหัสก่อนเก็บ DB + ปิดบังในหน้าเว็บ
     * - default: ค่าตั้งต้น (hardcode) เมื่อ DB ยังไม่มีค่า — ไม่อ่านจาก .env
     */
    public const REGISTRY = [
        // ── Gemini AI ──
        'gemini.api_key' => [
            'group' => 'gemini', 'label' => 'Gemini API Key', 'secret' => true,
            'help' => 'สมัครฟรีที่ aistudio.google.com/apikey',
        ],
        'gemini.model' => [
            'group' => 'gemini', 'label' => 'Gemini Model', 'secret' => false,
            'default' => 'gemini-2.5-flash-lite',
            'help' => 'แนะนำ gemini-2.5-flash-lite (โควต้าฟรีรายวันสูงกว่า flash) · 2.0-flash quota=0 ใช้ไม่ได้',
        ],

        // ── LINE Messaging API ──
        'line.channel_access_token' => [
            'group' => 'line', 'label' => 'Channel Access Token', 'secret' => true,
            'help' => 'จาก LINE Developers Console → Messaging API → Channel access token',
        ],
        'line.channel_secret' => [
            'group' => 'line', 'label' => 'Channel Secret', 'secret' => true,
            'help' => 'ใช้ตรวจลายเซ็น webhook (X-Line-Signature)',
        ],
        'line.recipient_id' => [
            'group' => 'line', 'label' => 'ผู้รับสรุป (User/Group ID)', 'secret' => false,
            'help' => 'User ID หรือ Group ID ปลายทางที่จะ push รายงานสรุปไปให้',
        ],

        // ── ตารางเวลาส่งสรุป ──
        'schedule.th_summary_time' => [
            'group' => 'schedule', 'label' => 'เวลาส่งสรุปหุ้นไทย (เวลาไทย)', 'secret' => false,
            'default' => '09:30', 'help' => 'ก่อนตลาด SET เปิด 10:00 น.',
        ],
        'schedule.us_summary_time_ny' => [
            'group' => 'schedule', 'label' => 'เวลาส่งสรุปหุ้น US (เวลา New York)', 'secret' => false,
            'default' => '09:00', 'help' => 'ก่อนตลาดเปิด 9:30 ET — ระบบแปลงเป็นเวลาไทยตาม DST ให้เอง',
        ],

        // ── ทั่วไป ──
        'general.default_exchange_rate' => [
            'group' => 'general', 'label' => 'อัตราแลกเปลี่ยน USD→THB เริ่มต้น', 'secret' => false,
            'default' => '33', 'help' => 'ใช้เป็นค่าตั้งต้นในหน้าวิเคราะห์/สรุป',
        ],
        'alert.price_threshold' => [
            'group' => 'general', 'label' => 'เกณฑ์แจ้งเตือนราคา (%)', 'secret' => false,
            'default' => '5', 'help' => 'แจ้งเตือนเมื่อราคาขยับเกิน % นี้ระหว่างวัน',
        ],
        'alert.volume_multiplier' => [
            'group' => 'general', 'label' => 'เกณฑ์แจ้งเตือน Volume (เท่า)', 'secret' => false,
            'default' => '2.5', 'help' => 'แจ้งเตือนเมื่อ volume วันนี้ > X เท่าของค่าเฉลี่ย 20 วัน',
        ],
    ];

    /**
     * อ่านค่า setting — ลำดับ: DB → default (ใน registry) → fallback
     * ⚠️ ไม่อ่านจาก .env/config โดยตั้งใจ — ค่าทั้งหมดต้องอยู่ใน DB เท่านั้น
     *    (จัดการผ่านหน้า /settings) ค่า default เป็นค่าตั้งต้น hardcode ไม่ใช่ .env
     */
    public function get(string $key, $fallback = null)
    {
        $all = $this->all();
        if (array_key_exists($key, $all) && $all[$key] !== null && $all[$key] !== '') {
            return $all[$key];
        }

        $meta = self::REGISTRY[$key] ?? null;
        if ($meta && array_key_exists('default', $meta)) {
            return $meta['default'];
        }

        return $fallback;
    }

    /**
     * บันทึกค่า — secret จะถูกเข้ารหัสด้วย APP_KEY ก่อนเก็บ
     */
    public function set(string $key, $value): void
    {
        $meta = self::REGISTRY[$key] ?? [];
        $isSecret = $meta['secret'] ?? false;

        $stored = ($isSecret && $value !== null && $value !== '')
            ? Crypt::encryptString($value)
            : $value;

        Setting::updateOrCreate(
            ['key' => $key],
            [
                'value'     => $stored,
                'group'     => $meta['group'] ?? 'general',
                'is_secret' => $isSecret,
            ]
        );

        Cache::forget(self::CACHE_KEY);
    }

    /**
     * ค่านี้ถูกตั้งใน DB แล้วหรือยัง (ใช้โชว์สถานะ •••• ในหน้า UI)
     */
    public function isSet(string $key): bool
    {
        $all = $this->all();
        return isset($all[$key]) && $all[$key] !== null && $all[$key] !== '';
    }

    /**
     * โหลด setting ทั้งหมดจาก DB (ถอดรหัส secret แล้ว) + cache ถาวร
     */
    private function all(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            $map = [];
            foreach (Setting::all() as $row) {
                $val = $row->value;
                if ($row->is_secret && $val) {
                    try {
                        $val = Crypt::decryptString($val);
                    } catch (\Throwable $e) {
                        $val = null; // ถอดรหัสไม่ได้ (เช่น APP_KEY เปลี่ยน) → ถือว่าไม่มีค่า
                    }
                }
                $map[$row->key] = $val;
            }
            return $map;
        });
    }

    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
