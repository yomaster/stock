<?php

namespace App\Console\Commands;

use App\Models\Stock;
use App\Models\StockPrice;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Carbon;

#[Signature('app:fetch-fund-data {symbol : รหัสกองทุนไทย เช่น K-GHRMF} {--years=5 : จำนวนปีย้อนหลัง}')]
#[Description('ดึง NAV กองทุนรวมไทยจาก SEC Thailand API')]
class FetchFundData extends Command
{
    public function handle(): int
    {
        $symbol = strtoupper(trim($this->argument('symbol')));
        $years  = (int) $this->option('years');

        $this->info("Fetching Thai fund NAV: {$symbol} ({$years} years)...");

        // 1. ดึง fund info จาก SEC
        $info = $this->fetchFundInfo($symbol);
        if (!$info) {
            $this->error("ไม่พบกองทุน {$symbol} ใน SEC Thailand — ตรวจสอบรหัสกองทุนให้ถูกต้อง");
            return self::FAILURE;
        }

        // 2. upsert Stock record (ใช้ stocks table เหมือนหุ้นปกติ)
        $stock = Stock::updateOrCreate(
            ['symbol' => $symbol],
            [
                'name'           => $info['name_th'] ?? $symbol,
                'currency'       => 'THB',
                'exchange'       => 'SEC_TH',
                'type'           => 'MUTUALFUND',
                'asset_category' => 'fund',
            ]
        );

        // 3. ดึง NAV ย้อนหลัง
        $startDate = now()->subYears($years)->format('Y-m-d');
        $endDate   = now()->format('Y-m-d');

        $navData = $this->fetchNavHistory($symbol, $startDate, $endDate);
        if (empty($navData)) {
            $this->warn("ดึง NAV สำเร็จแต่ไม่มีข้อมูลย้อนหลัง — บันทึก stock record ไว้แล้ว");
            return self::SUCCESS;
        }

        // 4. bulk upsert NAV ลง stock_prices (close = NAV per unit)
        $rows = [];
        foreach ($navData as $row) {
            $date = $row['nav_date'] ?? null;
            $nav  = $row['last_val'] ?? null;
            if (!$date || $nav === null) {
                continue;
            }
            $rows[] = [
                'stock_id'   => $stock->id,
                'date'       => Carbon::parse($date)->toDateString(),
                'open'       => null,
                'high'       => null,
                'low'        => null,
                'close'      => (float) $nav,
                'adj_close'  => (float) $nav,
                'volume'     => null,
                'dividends'  => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            StockPrice::upsert(
                $chunk,
                ['stock_id', 'date'],
                ['close', 'adj_close', 'updated_at']
            );
        }

        $nameTh = $info['name_th'] ?? '';
        $this->info("บันทึก NAV " . count($rows) . " วันสำเร็จ — {$symbol} ({$nameTh})");
        return self::SUCCESS;
    }

    /** ดึงข้อมูลกองทุนจาก SEC (ชื่อ, ประเภท) */
    private function fetchFundInfo(string $symbol): ?array
    {
        try {
            $resp = Http::timeout(15)
                ->withHeaders(['accept' => 'application/json'])
                ->get("https://api.sec.or.th/FundFactsheet/fund/{$symbol}");

            if ($resp->failed() || empty($resp->json())) {
                return null;
            }
            return $resp->json();
        } catch (\Throwable) {
            return null;
        }
    }

    /** ดึง NAV รายวันจาก SEC */
    private function fetchNavHistory(string $symbol, string $startDate, string $endDate): array
    {
        try {
            $resp = Http::timeout(30)
                ->withHeaders(['accept' => 'application/json'])
                ->get("https://api.sec.or.th/FundFactsheet/fund/{$symbol}/dailynav", [
                    'startDate' => $startDate,
                    'endDate'   => $endDate,
                ]);

            return $resp->successful() ? ($resp->json() ?? []) : [];
        } catch (\Throwable) {
            return [];
        }
    }
}
