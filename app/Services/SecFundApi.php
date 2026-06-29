<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * SEC Thailand Open API v2 — กองทุนรวม (portal ใหม่ secopendata.sec.or.th)
 *
 * - general-info : รายชื่อ บลจ./กองทุน (proj_id)         — key 'sec.factsheet_key'
 * - NAV รายวัน                                          — key 'sec.dailyinfo_key'
 *
 * pagination: response มี items[] + next_cursor — ส่ง ?next_cursor={ค่า} เพื่อขอหน้าถัดไป
 *             (next_cursor ว่าง = หมดแล้ว)  ⚠️ ชื่อ param คือ next_cursor ไม่ใช่ cursor
 */
class SecFundApi
{
    private const BASE = 'https://api.sec.or.th';

    public function __construct(private SettingsService $settings) {}

    public function hasFactsheetKey(): bool
    {
        return (bool) $this->settings->get('sec.factsheet_key');
    }

    public function hasDailyInfoKey(): bool
    {
        return (bool) $this->settings->get('sec.dailyinfo_key');
    }

    /**
     * ดึง profiles กองทุน 1 หน้า (full extraction) — GET /v2/fund/general-info/profiles
     * คืน ['items' => [...], 'next_cursor' => string]  (next_cursor ว่าง = หน้าสุดท้าย)
     */
    public function profilesPage(?string $cursor = null): array
    {
        $key = $this->settings->get('sec.factsheet_key');
        if (!$key) {
            return ['items' => [], 'next_cursor' => ''];
        }

        $query = $cursor ? ['next_cursor' => $cursor] : [];

        try {
            $resp = Http::timeout(30)
                ->withHeaders(['Ocp-Apim-Subscription-Key' => $key])
                ->get(self::BASE . '/v2/fund/general-info/profiles', $query);

            if (!$resp->successful()) {
                return ['items' => [], 'next_cursor' => ''];
            }
            return [
                'items'       => $resp->json('items') ?? [],
                'next_cursor' => (string) ($resp->json('next_cursor') ?? ''),
            ];
        } catch (\Throwable) {
            return ['items' => [], 'next_cursor' => ''];
        }
    }

    /**
     * NAV รายวัน 1 หน้า — GET /v2/fund/daily-info/nav?proj_id=X[&next_cursor=]
     * คืน ['items' => [...], 'next_cursor' => string]
     * แต่ละ item: {nav_date, last_val, fund_class_name, ...}
     *
     * ⚠️ endpoint นี้ไม่มี date filter (start/end/nav_date = 400) และคืนทุก class ปนกัน
     *    — ผู้เรียกต้อง paginate ทั้งหมดแล้วกรอง class + ช่วงวันที่เอง
     */
    public function navPage(string $projId, ?string $cursor = null): array
    {
        $key = $this->settings->get('sec.dailyinfo_key');
        if (!$key) {
            return ['items' => [], 'next_cursor' => ''];
        }

        $query = ['proj_id' => $projId];
        if ($cursor) {
            $query['next_cursor'] = $cursor;
        }

        try {
            $resp = Http::timeout(20)
                ->withHeaders(['Ocp-Apim-Subscription-Key' => $key])
                ->get(self::BASE . '/v2/fund/daily-info/nav', $query);

            if (!$resp->successful()) {
                return ['items' => [], 'next_cursor' => ''];
            }
            return [
                'items'       => $resp->json('items') ?? [],
                'next_cursor' => (string) ($resp->json('next_cursor') ?? ''),
            ];
        } catch (\Throwable) {
            return ['items' => [], 'next_cursor' => ''];
        }
    }

    /**
     * เลือก fund_class_name "ตัวแทน" จาก NAV หน้าแรก:
     * เอา 'main' ก่อน (เป็นชนิดหลักที่กองส่วนใหญ่มี) ไม่งั้นเอา class ที่มีข้อมูลเยอะสุด
     * คืน null ถ้ากองนี้ไม่มี NAV เลย
     */
    public function pickNavClass(string $projId): ?string
    {
        $items = $this->navPage($projId)['items'];
        if (!$items) {
            return null;
        }

        $counts = [];
        foreach ($items as $it) {
            $c = $it['fund_class_name'] ?? null;
            if ($c !== null) {
                $counts[$c] = ($counts[$c] ?? 0) + 1;
            }
        }
        if (!$counts) {
            return null;
        }
        if (isset($counts['main'])) {
            return 'main';
        }
        arsort($counts);
        return array_key_first($counts);
    }
}
