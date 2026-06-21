<?php

namespace App\Console\Commands;

use App\Models\News;
use App\Models\Stock;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

#[Signature('app:fetch-stock-news {symbol? : ระบุหุ้นเฉพาะตัว (เว้นว่าง = ทุกตัวในระบบ)} {--count=10 : จำนวนข่าวต่อหุ้น}')]
#[Description('ดึงข่าวรายหุ้นเจาะจงจาก Yahoo Finance search แล้ว tag symbol ให้อัตโนมัติ')]
class FetchStockNews extends Command
{
    public function handle(): int
    {
        $symbolInput = $this->argument('symbol');
        $count = (int) $this->option('count');

        $stocks = $symbolInput
            ? Stock::where('symbol', strtoupper($symbolInput))->get()
            : Stock::all();

        if ($stocks->isEmpty()) {
            $this->error('ไม่พบหุ้นในระบบ — เพิ่มหุ้นก่อนด้วย app:fetch-stock-data');
            return self::FAILURE;
        }

        // symbol ทั้งหมดในระบบ ใช้เช็ค relatedTickers ว่าตรงกับหุ้นที่เรามีไหม
        $knownSymbols = Stock::pluck('symbol')->map(fn ($s) => strtoupper($s))->toArray();

        $totalNew = 0;

        foreach ($stocks as $stock) {
            $this->info("ดึงข่าว {$stock->symbol}...");

            try {
                $response = Http::withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                ])->get('https://query1.finance.yahoo.com/v1/finance/search', [
                    'q'          => $stock->symbol,
                    'newsCount'  => $count,
                    'quotesCount' => 0,
                ]);

                if ($response->failed()) {
                    $this->error("  ดึงไม่สำเร็จ (HTTP {$response->status()})");
                    continue;
                }

                $newsItems = $response->json('news') ?? [];

                foreach ($newsItems as $item) {
                    $link  = $item['link'] ?? null;
                    $title = $item['title'] ?? null;
                    if (!$link || !$title) {
                        continue;
                    }

                    // ข้ามข่าวซ้ำ (เช็คจาก url)
                    if (News::where('url', $link)->exists()) {
                        continue;
                    }

                    // tag: หุ้นที่ค้นหา + relatedTickers ที่ตรงกับหุ้นในระบบ
                    $tags = [strtoupper($stock->symbol)];
                    foreach (($item['relatedTickers'] ?? []) as $rt) {
                        $rt = strtoupper($rt);
                        if (in_array($rt, $knownSymbols, true)) {
                            $tags[] = $rt;
                        }
                    }
                    $tags = array_values(array_unique($tags));

                    $publishedAt = isset($item['providerPublishTime'])
                        ? Carbon::createFromTimestamp($item['providerPublishTime'])->toDateTimeString()
                        : now()->toDateTimeString();

                    News::create([
                        'title'        => trim($title),
                        'url'          => $link,
                        'source'       => $item['publisher'] ?? 'Yahoo Finance',
                        'published_at' => $publishedAt,
                        'summary'      => null,
                        'sentiment'    => 'neutral',
                        'sentiment_score' => 0.00,
                        'symbols'      => json_encode($tags),
                    ]);

                    $totalNew++;
                }

                $this->info("  เพิ่มข่าวใหม่สำหรับ {$stock->symbol}");

            } catch (\Exception $e) {
                $this->error("  ผิดพลาด {$stock->symbol}: " . $e->getMessage());
            }
        }

        $this->info("เสร็จสิ้น — เพิ่มข่าวรายหุ้นใหม่ทั้งหมด {$totalNew} ข่าว");
        $this->line("แนะนำ: รัน <fg=yellow>php artisan app:summarize-news</> เพื่อแปลข่าวเป็นไทย");

        return self::SUCCESS;
    }
}
