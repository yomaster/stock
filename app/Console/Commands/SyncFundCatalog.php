<?php

namespace App\Console\Commands;

use App\Models\SecFund;
use App\Services\SecFundApi;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:sync-fund-catalog')]
#[Description('ดึง catalog กองทุนทั้งหมดจาก SEC มาเก็บใน sec_funds (ไว้ค้นหา autocomplete)')]
class SyncFundCatalog extends Command
{
    public function handle(SecFundApi $api): int
    {
        if (!$api->hasFactsheetKey()) {
            $this->error('ยังไม่ได้ตั้งค่า FundFactsheet Key — ตั้งที่หน้า /settings ก่อน');
            return self::FAILURE;
        }

        $this->info('Syncing fund catalog from SEC...');

        $cursor = null;
        $total  = 0;
        $pages  = 0;

        do {
            $page = $api->profilesPage($cursor);
            $rows = [];
            foreach ($page['items'] as $f) {
                $projId = $f['proj_id'] ?? null;
                if (!$projId) {
                    continue;
                }
                $rows[] = [
                    'proj_id'        => $projId,
                    'proj_abbr_name' => $f['proj_abbr_name'] ?? $projId,
                    'proj_name_th'   => $f['proj_name_th'] ?? ($f['proj_name_en'] ?? null),
                    'amc_name_th'    => $f['comp_name_th'] ?? null,
                    'fund_status'    => $f['fund_status'] ?? null,
                    'updated_at'     => now(),
                    'created_at'     => now(),
                ];
            }

            if ($rows) {
                // upsert: proj_id ซ้ำ = อัปเดตข้อมูลล่าสุด
                SecFund::upsert(
                    $rows,
                    ['proj_id'],
                    ['proj_abbr_name', 'proj_name_th', 'amc_name_th', 'fund_status', 'updated_at']
                );
                $total += count($rows);
            }

            $cursor = $page['next_cursor'] ?: null;
            $pages++;
            $this->line("  page {$pages}: +" . count($rows) . " (รวม {$total})");
        } while ($cursor);

        $this->info("เสร็จสิ้น — sync {$total} กองทุน ({$pages} หน้า)");
        return self::SUCCESS;
    }
}
