<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesUserStocks;
use App\Models\StockPrice;
use Illuminate\Http\Request;

class CompareController extends Controller
{
    use ScopesUserStocks;

    public function index(Request $request)
    {
        // เปรียบเทียบได้เฉพาะหุ้นที่ user ติดตาม
        $allStocks = $this->userStocks()->orderBy('symbol')->get();
        $selected  = (array) $request->input('symbols', []);
        $years     = (int) $request->input('years', 1);
        $years     = in_array($years, [1, 2, 3, 5, 10]) ? $years : 1;

        $datasets = [];
        $summary  = [];

        if (!empty($selected)) {
            $start = now()->subYears($years)->toDateString();

            // เก็บ close ของแต่ละหุ้นเป็น map[date] เพื่อ normalize เทียบ % การเติบโต
            $perStock = [];
            $allDates = [];
            foreach ($selected as $sym) {
                $stock = $allStocks->firstWhere('symbol', $sym);
                if (!$stock) {
                    continue;
                }
                $prices = StockPrice::where('stock_id', $stock->id)
                    ->where('date', '>=', $start)
                    ->orderBy('date', 'asc')
                    ->get(['date', 'close']);
                if ($prices->isEmpty()) {
                    continue;
                }

                $first = $prices->first()->close;
                $map = [];
                foreach ($prices as $p) {
                    // normalize ฐาน 100 ที่วันแรก → ทุกหุ้นเทียบ % กันได้แม้ราคาต่างสเกล
                    $map[$p->date] = $first > 0 ? round(($p->close / $first) * 100, 2) : null;
                    $allDates[$p->date] = true;
                }
                $perStock[$sym] = [
                    'stock' => $stock,
                    'map'   => $map,
                    'firstClose' => $first,
                    'lastClose'  => $prices->last()->close,
                ];
            }

            ksort($allDates);
            $labels = array_keys($allDates);

            foreach ($perStock as $sym => $info) {
                $data = array_map(fn ($d) => $info['map'][$d] ?? null, $labels);
                $datasets[] = [
                    'symbol' => $sym,
                    'data'   => $data,
                ];

                $return = $info['firstClose'] > 0
                    ? (($info['lastClose'] - $info['firstClose']) / $info['firstClose']) * 100
                    : 0;
                $summary[] = [
                    'symbol'   => $sym,
                    'name'     => $info['stock']->name,
                    'currency' => $info['stock']->currency,
                    'first'    => $info['firstClose'],
                    'last'     => $info['lastClose'],
                    'return'   => $return,
                ];
            }

            // เรียงตามผลตอบแทนมาก→น้อย
            usort($summary, fn ($a, $b) => $b['return'] <=> $a['return']);

            $chartLabels = $labels;
        }

        return view('stocks.compare', [
            'allStocks'   => $allStocks,
            'selected'    => $selected,
            'years'       => $years,
            'datasets'    => $datasets,
            'summary'     => $summary,
            'chartLabels' => $chartLabels ?? [],
        ]);
    }
}
