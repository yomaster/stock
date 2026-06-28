<?php

namespace App\Console\Commands;

use App\Models\Stock;
use App\Models\StockPrice;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Carbon;

#[Signature('app:fetch-stock-data {symbol? : The stock symbol to fetch} {--years=5 : Years of history to fetch}')]
#[Description('Fetch stock price history and dividends from Yahoo Finance')]
class FetchStockData extends Command
{
    /**
     * Execute the console command.
     */
    public function handle()
    {
        $symbolInput = $this->argument('symbol');
        $years = (int) $this->option('years');

        $symbols = [];
        if ($symbolInput) {
            $symbols[] = strtoupper($symbolInput);
        } else {
            // Default list if database is empty
            $dbSymbols = Stock::pluck('symbol')->toArray();
            if (empty($dbSymbols)) {
                $symbols = ['PTT.BK', 'ADVANC.BK', 'CPALL.BK', 'AAPL', 'TSLA', 'MSFT', 'NVDA'];
            } else {
                $symbols = $dbSymbols;
            }
        }

        $this->info("Fetching data for " . count($symbols) . " symbols for the last $years years...");

        $period2 = time();
        $period1 = $period2 - ($years * 365 * 24 * 60 * 60);

        foreach ($symbols as $symbol) {
            $this->info("Processing symbol: {$symbol}");
            
            // Fetch from Yahoo Finance Chart API with Chrome User Agent to bypass basic blocks
            $url = "https://query2.finance.yahoo.com/v8/finance/chart/{$symbol}";
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
            ])->get($url, [
                'period1' => $period1,
                'period2' => $period2,
                'interval' => '1d',
                'events' => 'div,split'
            ]);

            if ($response->failed()) {
                $this->error("Failed to fetch data for {$symbol}. HTTP Status: " . $response->status());
                continue;
            }

            $data = $response->json();
            $result = $data['chart']['result'][0] ?? null;

            if (!$result) {
                $this->error("No chart result found for {$symbol}");
                continue;
            }

            $meta = $result['meta'] ?? [];
            $currency = $meta['currency'] ?? ($this->isThaiStock($symbol) ? 'THB' : 'USD');
            $exchange = $meta['exchangeName'] ?? null;
            $instrumentType = $meta['instrumentType'] ?? 'EQUITY';

            // Create or update stock record
            $stock = Stock::updateOrCreate(
                ['symbol' => $symbol],
                [
                    'name'           => $this->getStockNamePlaceholder($symbol),
                    'currency'       => $currency,
                    'exchange'       => $exchange,
                    'type'           => $instrumentType,
                    // จัดกลุ่ม asset_category จาก Yahoo instrumentType
                    'asset_category' => match($instrumentType) {
                        'ETF'        => 'etf',
                        'MUTUALFUND' => 'fund',
                        default      => 'stock',
                    },
                ]
            );

            $timestamps = $result['timestamp'] ?? [];
            $quote = $result['indicators']['quote'][0] ?? [];
            $adjclose = $result['indicators']['adjclose'][0]['adjclose'] ?? [];
            
            $opens = $quote['open'] ?? [];
            $highs = $quote['high'] ?? [];
            $lows = $quote['low'] ?? [];
            $closes = $quote['close'] ?? [];
            $volumes = $quote['volume'] ?? [];

            // Fetch dividend events
            $dividendEvents = $result['events']['dividends'] ?? [];
            $dividendsByDate = [];
            foreach ($dividendEvents as $timestamp => $divInfo) {
                $dateStr = Carbon::createFromTimestamp($timestamp)->toDateString();
                $dividendsByDate[$dateStr] = (float) ($divInfo['amount'] ?? 0);
            }

            $pricesData = [];
            $count = count($timestamps);

            $this->info("Parsing {$count} daily price points for {$symbol}...");

            for ($i = 0; $i < $count; $i++) {
                if (!isset($closes[$i]) || $closes[$i] === null) {
                    continue; // Skip days with missing prices (e.g. holidays)
                }

                $date = Carbon::createFromTimestamp($timestamps[$i])->toDateString();
                $dividend = $dividendsByDate[$date] ?? 0.0000;

                $pricesData[] = [
                    'stock_id' => $stock->id,
                    'date' => $date,
                    'open' => isset($opens[$i]) ? (float) $opens[$i] : null,
                    'high' => isset($highs[$i]) ? (float) $highs[$i] : null,
                    'low' => isset($lows[$i]) ? (float) $lows[$i] : null,
                    'close' => (float) $closes[$i],
                    'adj_close' => isset($adjclose[$i]) ? (float) $adjclose[$i] : (float) $closes[$i],
                    'volume' => isset($volumes[$i]) ? (int) $volumes[$i] : null,
                    'dividends' => $dividend,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            // Bulk Upsert in chunks of 1000 records
            if (!empty($pricesData)) {
                $this->info("Saving database records for {$symbol}...");
                $chunks = array_chunk($pricesData, 1000);
                foreach ($chunks as $chunk) {
                    StockPrice::upsert(
                        $chunk,
                        ['stock_id', 'date'],
                        ['open', 'high', 'low', 'close', 'adj_close', 'volume', 'dividends', 'updated_at']
                    );
                }
            }

            $this->info("Successfully processed {$symbol}!");
        }

        $this->info("Stock data fetch completed.");
    }

    private function isThaiStock($symbol)
    {
        return str_ends_with(strtoupper($symbol), '.BK');
    }

    private function getStockNamePlaceholder($symbol)
    {
        $names = [
            'PTT.BK' => 'PTT Public Company Limited',
            'ADVANC.BK' => 'Advanced Info Service PCL',
            'CPALL.BK' => 'CP ALL Public Company Limited',
            'AAPL' => 'Apple Inc.',
            'TSLA' => 'Tesla, Inc.',
            'MSFT' => 'Microsoft Corporation',
            'NVDA' => 'NVIDIA Corporation',
        ];

        return $names[strtoupper($symbol)] ?? strtoupper($symbol);
    }
}
