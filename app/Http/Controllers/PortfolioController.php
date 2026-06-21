<?php

namespace App\Http\Controllers;

use App\Models\Portfolio;
use App\Models\PortfolioItem;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Services\GeminiService;
use App\Services\SettingsService;
use Illuminate\Http\Request;

class PortfolioController extends Controller
{
    public function __construct(private SettingsService $settings) {}

    public function index()
    {
        $portfolio = $this->defaultPortfolio();
        $stocks    = Stock::orderBy('symbol')->get();
        $data      = $this->buildHoldings($portfolio);

        return view('portfolio.index', array_merge($data, [
            'portfolio' => $portfolio,
            'stocks'    => $stocks,
            'rate'      => $this->exchangeRate(),
        ]));
    }

    public function storeItem(Request $request)
    {
        $validated = $request->validate([
            'stock_id'       => 'required|exists:stocks,id',
            'shares'         => 'required|numeric|min:0.0001',
            'purchase_price' => 'required|numeric|min:0',
            'purchase_date'  => 'nullable|date',
        ]);

        $this->defaultPortfolio()->items()->create($validated);

        return back()->with('success', 'เพิ่มหุ้นเข้าพอร์ตแล้ว');
    }

    public function destroyItem(PortfolioItem $item)
    {
        $item->delete();
        return back()->with('success', 'ลบหุ้นออกจากพอร์ตแล้ว');
    }

    /**
     * AI ตรวจสุขภาพพอร์ต (AJAX) — วิเคราะห์การกระจุกตัว + คำแนะนำ rebalance
     */
    public function healthCheck(GeminiService $gemini)
    {
        $portfolio = $this->defaultPortfolio();
        $data = $this->buildHoldings($portfolio);

        if (empty($data['holdings'])) {
            return response()->json(['success' => false, 'message' => 'ยังไม่มีหุ้นในพอร์ต']);
        }

        // สรุป holdings ให้ AI (สัดส่วนเป็น % ของมูลค่ารวมในสกุล THB)
        $lines = [];
        foreach ($data['holdings'] as $h) {
            $lines[] = "- {$h['symbol']} ({$h['name']}): สัดส่วน " . number_format($h['allocation'], 1)
                . "% มูลค่า " . number_format($h['value_thb'], 0) . " บาท"
                . " กำไร/ขาดทุน " . number_format($h['pl_percent'], 1) . "%";
        }
        $block = implode("\n", $lines);

        $prompt = "คุณคือที่ปรึกษาการลงทุนมืออาชีพ ช่วยตรวจสุขภาพพอร์ตการลงทุนต่อไปนี้ "
            . "(มูลค่ารวม " . number_format($data['total_value_thb'], 0) . " บาท):\n{$block}\n\n"
            . "วิเคราะห์เป็นภาษาไทยให้นักลงทุนมือใหม่เข้าใจง่าย ครอบคลุม:\n"
            . "1. ภาพรวมความเสี่ยง (กระจุกตัวในหุ้นตัวเดียว/กลุ่มธุรกิจเดียวเกินไปไหม)\n"
            . "2. การกระจายความเสี่ยง (กลุ่มอุตสาหกรรม/ประเทศ)\n"
            . "3. คำแนะนำปรับสมดุล (Rebalance) ที่ทำได้จริง\n\n"
            . "ตอบกระชับเป็นข้อๆ ใช้ bullet (-) ไม่ต้องมี markdown หนา";

        $result = $gemini->generateText($prompt, ['maxOutputTokens' => 1024]);

        if (!$result) {
            return response()->json(['success' => false, 'message' => 'AI วิเคราะห์ไม่สำเร็จ ลองใหม่อีกครั้ง']);
        }

        return response()->json(['success' => true, 'analysis' => trim($result)]);
    }

    // ───────────────────────── helpers ─────────────────────────

    private function defaultPortfolio(): Portfolio
    {
        return Portfolio::firstOrCreate(
            ['name' => 'พอร์ตของฉัน'],
            ['description' => 'พอร์ตการลงทุนหลัก']
        );
    }

    private function exchangeRate(): float
    {
        return (float) $this->settings->get('general.default_exchange_rate', 33);
    }

    /**
     * คำนวณ holdings: มูลค่าปัจจุบัน, ทุน, กำไร/ขาดทุน, สัดส่วน (แปลง USD→THB ด้วย rate)
     */
    private function buildHoldings(Portfolio $portfolio): array
    {
        $rate = $this->exchangeRate();
        $items = $portfolio->items()->with('stock')->get();

        $holdings = [];
        $totalValueThb = 0;
        $totalCostThb  = 0;

        foreach ($items as $item) {
            $stock = $item->stock;
            if (!$stock) {
                continue;
            }

            $latest = StockPrice::where('stock_id', $stock->id)
                ->orderBy('date', 'desc')->first();
            $currentPrice = $latest?->close ?? $item->purchase_price;

            $value = $currentPrice * $item->shares;       // มูลค่าปัจจุบัน (สกุลหุ้น)
            $cost  = $item->purchase_price * $item->shares; // ทุน (สกุลหุ้น)

            $isUsd = !str_ends_with(strtoupper($stock->symbol), '.BK');
            $valueThb = $isUsd ? $value * $rate : $value;
            $costThb  = $isUsd ? $cost * $rate : $cost;

            $totalValueThb += $valueThb;
            $totalCostThb  += $costThb;

            $holdings[] = [
                'id'            => $item->id,
                'symbol'        => $stock->symbol,
                'name'          => $stock->name,
                'currency'      => $stock->currency,
                'shares'        => $item->shares,
                'purchase_price' => $item->purchase_price,
                'current_price' => $currentPrice,
                'value'         => $value,
                'value_thb'     => $valueThb,
                'cost'          => $cost,
                'pl_value'      => $value - $cost,
                'pl_percent'    => $cost > 0 ? (($value - $cost) / $cost) * 100 : 0,
            ];
        }

        // คำนวณสัดส่วน % หลังได้ total
        foreach ($holdings as &$h) {
            $h['allocation'] = $totalValueThb > 0 ? ($h['value_thb'] / $totalValueThb) * 100 : 0;
        }
        unset($h);

        // เรียงตามมูลค่ามาก→น้อย
        usort($holdings, fn ($a, $b) => $b['value_thb'] <=> $a['value_thb']);

        return [
            'holdings'         => $holdings,
            'total_value_thb'  => $totalValueThb,
            'total_cost_thb'   => $totalCostThb,
            'total_pl_thb'     => $totalValueThb - $totalCostThb,
            'total_pl_percent' => $totalCostThb > 0 ? (($totalValueThb - $totalCostThb) / $totalCostThb) * 100 : 0,
        ];
    }
}
