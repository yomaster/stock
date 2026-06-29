<?php

namespace App\Jobs;

use App\Models\Stock;
use App\Models\StockPrice;
use App\Services\SecFundApi;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * ดึง NAV กองทุนย้อนหลังจาก SEC /v2/fund/daily-info/nav
 *
 * endpoint คืน NAV ทุก class ปนกัน + ไม่มี date filter → ต้อง paginate (next_cursor)
 * แล้วกรองเฉพาะ class ตัวแทน (stock.sec_nav_class) + ช่วงวันที่ฝั่งเราเอง
 *
 * 1 หน้า = 100 records → 1 กอง 5 ปี ≈ 13 หน้า (ดีกว่ายิงทีละวัน 1,250 ครั้งมาก)
 * job หนึ่งดึง MAX_PAGES หน้า แล้ว re-dispatch ต่อด้วย cursor — อยู่ใต้ queue:work
 * --max-time=55 ของ scheduler (routes/console.php) สบายๆ
 */
class FetchFundNavJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** จำนวนหน้าต่อ 1 job (100 records/หน้า) — 15 calls × ~0.4s ≈ 6s */
    private const MAX_PAGES = 15;
    /** กันลูปยาวผิดปกติ: รวมทุก job ไม่เกินจำนวนนี้ */
    private const HARD_PAGE_CAP = 80;

    public int $tries = 3;
    public int $backoff = 30;

    /**
     * @param int         $stockId  id ของ Stock (asset_category='fund')
     * @param string      $projId   proj_id ของ SEC
     * @param string      $navClass fund_class_name ตัวแทน (กรองเฉพาะ class นี้)
     * @param string      $fromDate วันที่ย้อนหลังสุดที่ต้องการ (Y-m-d)
     * @param string|null $cursor   next_cursor ของหน้าถัดไป (null = เริ่มหน้าแรก)
     * @param int         $pagesDone จำนวนหน้าที่ดึงมาแล้วรวมทุก job (กัน loop)
     */
    public function __construct(
        public int $stockId,
        public string $projId,
        public string $navClass,
        public string $fromDate,
        public ?string $cursor = null,
        public int $pagesDone = 0,
    ) {}

    public function handle(SecFundApi $api): void
    {
        if (!$api->hasDailyInfoKey()) {
            return; // ไม่มี key → ทำต่อไม่ได้
        }

        $cursor    = $this->cursor;
        $pagesDone = $this->pagesDone;
        $rows      = [];

        for ($i = 0; $i < self::MAX_PAGES && $pagesDone < self::HARD_PAGE_CAP; $i++) {
            $page = $api->navPage($this->projId, $cursor);
            $pagesDone++;

            foreach ($page['items'] as $it) {
                // กรองเฉพาะ class ตัวแทน + วันที่ในช่วงที่ต้องการ
                if (($it['fund_class_name'] ?? null) !== $this->navClass) {
                    continue;
                }
                $date = $it['nav_date'] ?? null;
                $nav  = $it['last_val'] ?? null;
                if (!$date || !is_numeric($nav) || $date < $this->fromDate) {
                    continue;
                }
                $rows[] = [
                    'stock_id'   => $this->stockId,
                    'date'       => $date,
                    'close'      => (float) $nav,
                    'adj_close'  => (float) $nav,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            $cursor = $page['next_cursor'] ?: null;
            if (!$cursor) {
                break; // หมดข้อมูลแล้ว
            }
        }

        if ($rows) {
            // close = NAV ต่อหน่วย (รูปแบบเดียวกับราคาหุ้น → PortfolioService ใช้ได้เลย)
            StockPrice::upsert($rows, ['stock_id', 'date'], ['close', 'adj_close', 'updated_at']);
        }

        // ยังมีหน้าถัดไป + ยังไม่ชน cap → ดึงต่อ
        if ($cursor && $pagesDone < self::HARD_PAGE_CAP) {
            self::dispatch($this->stockId, $this->projId, $this->navClass, $this->fromDate, $cursor, $pagesDone);
        }
    }
}
