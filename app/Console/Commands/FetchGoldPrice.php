<?php

namespace App\Console\Commands;

use App\Models\Stock;
use App\Models\StockPrice;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

#[Signature('app:fetch-gold-price {--days=7 : ดึงราคาย้อนหลังกี่วัน}')]
#[Description('ดึงราคาทองคำแท่ง 96.5% จากสมาคมค้าทองคำ (GTA)')]
class FetchGoldPrice extends Command
{
    private const API = 'https://goldtraders.or.th/api/GoldPricesDaily/pricechanges';

    public function handle(): int
    {
        // self-heal + repair: สร้าง/ซ่อม gold asset ให้เป็นทองเสมอ
        // ใช้ updateOrCreate (ไม่ใช่ firstOrCreate) เพื่อ "บังคับ" attr กลับมาถูก
        // เผื่อแถว GOLD โดน fetch-stock-data flip เป็น Barrick (asset_category=stock, USD)
        $gold = Stock::updateOrCreate(
            ['symbol' => 'GOLD'],
            [
                'name'           => 'ทองคำแท่ง 96.5%',
                'currency'       => 'THB',
                'exchange'       => 'GTA',
                'type'           => 'GOLD',
                'asset_category' => 'gold',
            ]
        );

        $cursor = now()->subDays((int) $this->option('days'))->startOfDay();
        $end    = now();

        // GTA อัปเดตหลายครั้ง/วัน → เก็บแถวล่าสุดของแต่ละวัน = ราคาปิดของวันนั้น
        // ดึงทีละ ~1 ปี (chunk) — ช่วงยาวๆ ในครั้งเดียว response ใหญ่/ช้าเกิน
        $byDate = [];
        while ($cursor->lessThanOrEqualTo($end)) {
            $winStart = $cursor->copy();
            $winEnd   = $cursor->copy()->addYear()->subDay();
            if ($winEnd->greaterThan($end)) {
                $winEnd = $end->copy();
            }

            try {
                $resp = Http::timeout(60)
                    ->withHeaders(['accept' => 'application/json', 'User-Agent' => 'Mozilla/5.0'])
                    ->get(self::API, [
                        'StartDate' => $winStart->format('Y-m-d'),
                        'EndDate'   => $winEnd->format('Y-m-d'),
                    ]);

                if (!$resp->successful()) {
                    $this->error("ช่วง {$winStart->format('Y-m-d')} ดึงไม่สำเร็จ: HTTP " . $resp->status());
                    return self::FAILURE;
                }
                $rows = $resp->json() ?? [];
            } catch (\Throwable $e) {
                $this->error('error: ' . $e->getMessage());
                return self::FAILURE;
            }

            foreach ($rows as $r) {
                $t   = $r['asTime'] ?? null;
                $bid = $r['bL_BuyPrice'] ?? null;   // รับซื้อ
                $ask = $r['bL_SellPrice'] ?? null;  // ขายออก
                if (!$t || $bid === null) {
                    continue;
                }
                $date = substr($t, 0, 10);
                if (!isset($byDate[$date]) || $t > $byDate[$date]['t']) {
                    $byDate[$date] = ['t' => $t, 'bid' => (float) $bid, 'ask' => $ask !== null ? (float) $ask : null];
                }
            }
            $this->line("  {$winStart->format('Y-m-d')} → {$winEnd->format('Y-m-d')}: " . count($rows) . ' rows');

            $cursor->addYear();
        }

        $upserts = [];
        foreach ($byDate as $date => $d) {
            $upserts[] = [
                'stock_id'   => $gold->id,
                'date'       => $date,
                'close'      => $d['bid'],   // รับซื้อ (ใช้คิดมูลค่าพอร์ต)
                'open'       => $d['ask'],   // ขายออก (ราคาตอนซื้อ)
                'adj_close'  => $d['bid'],
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($upserts, 500) as $chunk) {
            StockPrice::upsert($chunk, ['stock_id', 'date'], ['close', 'open', 'adj_close', 'updated_at']);
        }

        $last = $byDate ? end($byDate)['bid'] : null;
        $this->info('บันทึกราคาทอง ' . count($upserts) . ' วัน' . ($last ? " (ล่าสุดรับซื้อ {$last} บาท/บาททอง)" : ''));
        return self::SUCCESS;
    }
}
