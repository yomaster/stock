<?php

namespace App\Console\Commands;

use App\Models\News;
use App\Models\Stock;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Carbon;

#[Signature('app:fetch-news')]
#[Description('Fetch financial news from Thai and US RSS Feeds')]
class FetchNews extends Command
{
    /**
     * Execute the console command.
     */
    public function handle()
    {
        $feeds = [
            'yahoo_us' => [
                'url' => 'https://finance.yahoo.com/news/rssindex',
                'source' => 'Yahoo Finance US'
            ],
            'thailand_business' => [
                'url' => 'https://thailand-business-news.com/feed',
                'source' => 'Thailand Business News'
            ],
            'settrade_research' => [
                'url' => 'http://feeds2.feedburner.com/settrade/researchStock?format=xml',
                'source' => 'Settrade Research'
            ]
        ];

        // Retrieve existing stock symbols from database
        $stockSymbols = Stock::pluck('symbol')->toArray();
        $cleanTickers = array_map(function($sym) {
            return explode('.', $sym)[0];
        }, $stockSymbols);

        $newCount = 0;

        foreach ($feeds as $key => $feedInfo) {
            $this->info("Fetching feed from: {$feedInfo['source']}");
            
            try {
                $response = Http::withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
                ])->get($feedInfo['url']);

                if ($response->failed()) {
                    $this->error("Failed to fetch feed: {$feedInfo['source']}");
                    continue;
                }

                $xmlString = $response->body();
                libxml_use_internal_errors(true);
                $xml = simplexml_load_string($xmlString);

                if (!$xml) {
                    $this->error("Failed to parse XML from {$feedInfo['source']}");
                    continue;
                }

                $items = $xml->channel->item ?? [];
                
                foreach ($items as $item) {
                    $title = (string) $item->title;
                    $link = (string) $item->link;
                    $pubDate = (string) $item->pubDate;
                    $description = strip_tags((string) $item->description);

                    // Skip duplicate news by checking url
                    if (News::where('url', $link)->exists()) {
                        continue;
                    }

                    try {
                        $publishedAt = Carbon::parse($pubDate)->toDateTimeString();
                    } catch (\Exception $e) {
                        $publishedAt = now()->toDateTimeString();
                    }

                    // Simple keyword matching to tag related stocks
                    $relatedSymbols = [];
                    foreach ($stockSymbols as $index => $symbol) {
                        $ticker = $cleanTickers[$index];
                        // Match using word boundaries to avoid false positives (e.g. matching 'T' inside random words)
                        $pattern = '/\b' . preg_quote($ticker, '/') . '\b/i';
                        
                        if (preg_match($pattern, $title) || preg_match($pattern, $description)) {
                            $relatedSymbols[] = $symbol;
                        }
                    }

                    News::create([
                        'title' => $title,
                        'url' => $link,
                        'source' => $feedInfo['source'],
                        'published_at' => $publishedAt,
                        'summary' => $description ?: null,
                        'sentiment' => 'neutral',
                        'sentiment_score' => 0.00,
                        'symbols' => !empty($relatedSymbols) ? json_encode($relatedSymbols) : null,
                    ]);

                    $newCount++;
                }

            } catch (\Exception $e) {
                $this->error("Error processing {$feedInfo['source']}: " . $e->getMessage());
            }
        }

        $this->info("Successfully fetched {$newCount} new news articles.");
    }
}
