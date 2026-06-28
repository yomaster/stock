<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

class SettingsService
{
    private const CACHE_KEY = 'app_settings';

    /** รายชื่อ Gemini model ที่รองรับ — key = model ID ส่งไป API, value = label ใน UI */
    public const GEMINI_MODELS = [
        'gemini-2.5-flash-lite' => 'Gemini 2.5 Flash Lite (ประหยัดสุด · RPM 4K)',
        'gemini-2.5-flash'      => 'Gemini 2.5 Flash (RPM 1K)',
        'gemini-2.5-pro'        => 'Gemini 2.5 Pro · แนะนำสำหรับวิเคราะห์ (RPM 150)',
        'gemini-3.5-flash'      => 'Gemini 3.5 Flash (RPM 1K)',
        'gemini-3.1-flash-lite' => 'Gemini 3.1 Flash Lite (RPM 4K)',
        'gemini-3.1-pro'        => 'Gemini 3.1 Pro · ฉลาดสุด (RPM 25)',
    ];

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
        'gemini.model_analysis' => [
            'group' => 'gemini', 'label' => 'Model วิเคราะห์หุ้น (/ask)', 'secret' => false,
            'type' => 'select', 'default' => 'gemini-2.5-pro',
            'options' => self::GEMINI_MODELS,
            'help' => 'ใช้กับคำสั่ง /ask — Pro model ให้ผลวิเคราะห์แม่นยำกว่า (RPM ต่ำกว่า แต่งานนี้รันน้อยครั้ง)',
        ],
        'gemini.model_summary' => [
            'group' => 'gemini', 'label' => 'Model สรุปข่าว (Morning)', 'secret' => false,
            'type' => 'select', 'default' => 'gemini-2.5-flash-lite',
            'options' => self::GEMINI_MODELS,
            'help' => 'ใช้กับสรุปเช้า — Flash Lite เพียงพอ ประหยัด token และ RPM สูงกว่า',
        ],

        // ── Messaging Provider (เลือกใช้ LINE หรือ Telegram ทีละตัว) ──
        'messaging.provider' => [
            'group' => 'messaging', 'label' => 'ช่องทางส่งข้อความ (Provider)', 'secret' => false,
            'type' => 'select', 'default' => 'line',
            'options' => ['line' => 'LINE Messaging API', 'telegram' => 'Telegram Bot API'],
            'help' => 'เลือกใช้ทีละตัว — คำสั่งบอท + สรุป + แจ้งเตือน จะส่งผ่าน provider ที่เลือก (สลับแล้ว user ต้อง /link ใหม่)',
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

        // ── Telegram Bot API ──
        'telegram.bot_token' => [
            'group' => 'telegram', 'label' => 'Telegram Bot Token', 'secret' => true,
            'help' => 'จาก @BotFather → /newbot → token (รูปแบบ 123456:ABC...)',
        ],
        'telegram.bot_username' => [
            'group' => 'telegram', 'label' => 'Bot Username (ไม่ต้องมี @)', 'secret' => false,
            'help' => 'ชื่อบอท เช่น mystock_bot — ใช้สร้างลิงก์ t.me/<username> ในหน้าโปรไฟล์',
        ],
        'telegram.webhook_secret' => [
            'group' => 'telegram', 'label' => 'Webhook Secret Token (ออปชัน)', 'secret' => true,
            'help' => 'สุ่มสตริงไว้ตรวจ webhook — ใส่ค่าเดียวกันตอน setWebhook (secret_token)',
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

        // ── SEC Thailand API ──
        'sec.api_key' => [
            'group' => 'sec', 'label' => 'SEC Thailand Subscription Key', 'secret' => true,
            'help' => 'ลงทะเบียนฟรีที่ apiportal.sec.or.th → สมัคร product "FundFactsheet" → คัดลอก subscription key',
        ],

        // ── ทั่วไป ──
        'general.default_exchange_rate' => [
            'group' => 'general', 'label' => 'อัตราแลกเปลี่ยน USD→THB เริ่มต้น', 'secret' => false,
            'default' => '33', 'help' => 'ใช้เป็นค่าตั้งต้นในหน้าวิเคราะห์/สรุป',
        ],
        // หมายเหตุ: เกณฑ์แจ้งเตือนราคา/volume + recipient ย้ายไปเป็น per-user (ตาราง users)
        //          ตั้งค่าได้ที่หน้าโปรไฟล์ของแต่ละคน — ไม่ใช่ global settings อีกต่อไป
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
        try {
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
        } catch (\Throwable $e) {
            // ตาราง settings/cache ยังไม่ถูกสร้าง (ก่อน migrate ครั้งแรก) → ใช้ค่า default แทน
            // กัน deadlock: artisan ทุกคำสั่งโหลด console.php → เรียก SettingsService
            return [];
        }
    }

    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
