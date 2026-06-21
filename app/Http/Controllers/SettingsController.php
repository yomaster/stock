<?php

namespace App\Http\Controllers;

use App\Services\SettingsService;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function __construct(private SettingsService $settings) {}

    public function index()
    {
        // จัดกลุ่ม registry ตาม group สำหรับ render การ์ดในหน้า UI
        $groups = [];
        foreach (SettingsService::REGISTRY as $key => $meta) {
            $groups[$meta['group']][$key] = [
                'meta'   => $meta,
                'value'  => ($meta['secret'] ?? false) ? null : $this->settings->get($key),
                'is_set' => $this->settings->isSet($key),
            ];
        }

        $groupLabels = [
            'gemini'   => ['title' => 'Gemini AI', 'icon' => '🤖'],
            'line'     => ['title' => 'LINE Messaging API', 'icon' => '💬'],
            'schedule' => ['title' => 'ตารางเวลาส่งสรุป', 'icon' => '⏰'],
            'general'  => ['title' => 'ทั่วไป', 'icon' => '⚙️'],
        ];

        return view('settings.index', compact('groups', 'groupLabels'));
    }

    public function update(Request $request)
    {
        foreach (SettingsService::REGISTRY as $key => $meta) {
            // input name แทน . ด้วย __ (กัน Laravel มอง dot เป็น nested array)
            $field = str_replace('.', '__', $key);
            $value = $request->input($field);

            // secret: ถ้าเว้นว่าง = ไม่เปลี่ยน (กันค่าเดิมโดนล้าง)
            if (($meta['secret'] ?? false) && ($value === null || $value === '')) {
                continue;
            }

            $this->settings->set($key, $value);
        }

        return back()->with('success', 'บันทึกการตั้งค่าเรียบร้อยแล้ว');
    }
}
