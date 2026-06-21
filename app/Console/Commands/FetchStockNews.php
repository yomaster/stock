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
#[Description('ดึงข่าวรายหุ้น: หุ้นไทย (.BK) ใช้ Google News ภาษาไทย / หุ้น US ใช้ Yahoo Finance')]
class FetchStockNews extends Command
{
    private array $knownSymbols = [];

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
        $this->knownSymbols = Stock::pluck('symbol')->map(fn ($s) => strtoupper($s))->toArray();

        $totalNew = 0;

        foreach ($stocks as $stock) {
            $isThai = str_ends_with(strtoupper($stock->symbol), '.BK');
            $this->info("ดึงข่าว {$stock->symbol} (" . ($isThai ? 'ข่าวไทย' : 'US') . ")...");

            try {
                $added = $isThai
                    ? $this->fetchThaiNews($stock, $count)
                    : $this->fetchUsNews($stock, $count);
                $totalNew += $added;
                $this->info("  + {$added} ข่าวใหม่");
            } catch (\Exception $e) {
                $this->error("  ผิดพลาด {$stock->symbol}: " . $e->getMessage());
            }
        }

        $this->info("เสร็จสิ้น — เพิ่มข่าวรายหุ้นใหม่ทั้งหมด {$totalNew} ข่าว");
        $this->line("แนะนำ: รัน <fg=yellow>php artisan app:summarize-news</> เพื่อแปลข่าว US เป็นไทย");

        return self::SUCCESS;
    }

    /**
     * หุ้น US — Yahoo Finance search (มี relatedTickers ให้ tag)
     */
    private function fetchUsNews(Stock $stock, int $count): int
    {
        $response = Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        ])->get('https://query1.finance.yahoo.com/v1/finance/search', [
            'q'           => $stock->symbol,
            'newsCount'   => $count,
            'quotesCount' => 0,
        ]);

        if ($response->failed()) {
            $this->error("  ดึงไม่สำเร็จ (HTTP {$response->status()})");
            return 0;
        }

        $added = 0;
        foreach (($response->json('news') ?? []) as $item) {
            $link  = $item['link'] ?? null;
            $title = $item['title'] ?? null;
            if (!$link || !$title || News::where('url', $link)->exists()) {
                continue;
            }

            // tag: หุ้นที่ค้นหา + relatedTickers ที่ตรงกับหุ้นในระบบ
            $tags = [strtoupper($stock->symbol)];
            foreach (($item['relatedTickers'] ?? []) as $rt) {
                $rt = strtoupper($rt);
                if (in_array($rt, $this->knownSymbols, true)) {
                    $tags[] = $rt;
                }
            }

            News::create([
                'title'           => trim($title),
                'url'             => $link,
                'source'          => $item['publisher'] ?? 'Yahoo Finance',
                'published_at'    => isset($item['providerPublishTime'])
                    ? Carbon::createFromTimestamp($item['providerPublishTime'])->toDateTimeString()
                    : now()->toDateTimeString(),
                'summary'         => null,
                'sentiment'       => 'neutral',
                'sentiment_score' => 0.00,
                'symbols'         => json_encode(array_values(array_unique($tags))),
            ]);
            $added++;
        }

        return $added;
    }

    /**
     * หุ้นไทย — Google News RSS ภาษาไทย (ข่าวเป็นไทยอยู่แล้ว ไม่ต้องแปล)
     */
    private function fetchThaiNews(Stock $stock, int $count): int
    {
        // ใช้ ticker ไม่รวม .BK + คำว่า "หุ้น" เพื่อให้ได้ข่าวเกี่ยวกับหุ้นตัวนั้น
        $ticker = explode('.', $stock->symbol)[0];
        $query  = $ticker . ' หุ้น';

        $response = Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        ])->get('https://news.google.com/rss/search', [
            'q'    => $query,
            'hl'   => 'th',
            'gl'   => 'TH',
            'ceid' => 'TH:th',
        ]);

        if ($response->failed()) {
            $this->error("  ดึงไม่สำเร็จ (HTTP {$response->status()})");
            return 0;
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($response->body());
        if (!$xml || !isset($xml->channel->item)) {
            return 0;
        }

        $added = 0;
        $i = 0;
        foreach ($xml->channel->item as $item) {
            if ($i++ >= $count) {
                break;
            }

            $rawTitle = trim((string) $item->title);
            $link     = trim((string) $item->link);
            if (!$rawTitle || !$link || News::where('url', $link)->exists()) {
                continue;
            }

            // ชื่อแหล่งข่าวอยู่ใน <source>
            $source = isset($item->source) ? trim((string) $item->source) : 'Google News';
            $title  = $this->cleanGoogleTitle($rawTitle);

            $publishedAt = now()->toDateTimeString();
            if (!empty($item->pubDate)) {
                try {
                    $publishedAt = Carbon::parse((string) $item->pubDate)->toDateTimeString();
                } catch (\Exception $e) {
                    // ใช้ now() ตามเดิม
                }
            }

            // ข่าวไทยอยู่แล้ว — เก็บลง title_th/summary_th เลย ไม่ต้องเรียก AI แปล
            News::create([
                'title'           => $title,
                'title_th'        => $title,
                'url'             => $link,
                'source'          => $source,
                'published_at'    => $publishedAt,
                'summary'         => null,
                'sentiment'       => 'neutral',
                'sentiment_score' => 0.00,
                'symbols'         => json_encode([strtoupper($stock->symbol)]),
            ]);
            $added++;
        }

        return $added;
    }

    /**
     * Google News title = "หัวข้อข่าว - แหล่งข่าว"
     * บางเว็บใส่ชื่อยาว (SEO) ต่อท้าย → ตัดที่ " - " ตัวแรก (headline อยู่หน้าสุดเสมอ)
     * guard: ตัดเฉพาะเมื่อ headline ยาวพอ (>10 ตัว) กันหัวข้อสั้นโดนตัดผิด
     */
    private function cleanGoogleTitle(string $title): string
    {
        $pos = mb_strpos($title, ' - ');
        if ($pos !== false && $pos >= 10) {
            return trim(mb_substr($title, 0, $pos));
        }
        return $title;
    }
}
