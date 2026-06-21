<?php

namespace App\Http\Controllers;

use App\Models\Portfolio;
use App\Models\PortfolioItem;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Services\GeminiService;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

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
        $request->validate([
            'stock_id'      => 'required|exists:stocks,id',
            'mode'          => 'required|in:amount,shares',
            'purchase_date' => 'nullable|date|before_or_equal:today',
        ]);

        $stock = Stock::findOrFail($request->input('stock_id'));
        $date  = $request->input('purchase_date') ?: now()->toDateString();
        $mode  = $request->input('mode');

        // ราคาปิดหุ้น (สกุลของหุ้น) ณ วันที่ หรือวันทำการก่อนหน้าที่ใกล้ที่สุด
        $priceNative = $this->historicalPrice($stock->id, $date);
        if (!$priceNative) {
            return back()->with('error', "ไม่มีข้อมูลราคา {$stock->symbol} ณ วันที่เลือก — ลองวันหลังจากนี้ หรืออัปเดตข้อมูลหุ้นก่อน");
        }

        if ($mode === 'shares') {
            // โหมดรวบรัด: ใส่จำนวนหุ้นที่ถืออยู่ + ต้นทุนเฉลี่ย (ไม่บังคับ)
            $data = $request->validate([
                'shares'   => 'required|numeric|min:0.0001',
                'avg_cost' => 'nullable|numeric|min:0',
            ]);
            $shares = (float) $data['shares'];

            if (!empty($data['avg_cost'])) {
                // มีต้นทุนเฉลี่ย (สกุลของหุ้น) → คำนวณเงินลงทุนรวม
                $purchasePrice    = (float) $data['avg_cost'];
                $invested         = round($purchasePrice * $shares, 2);
                $investedCurrency = $stock->currency;
                $detail = number_format($shares, 4) . " หุ้น @ ต้นทุน " . number_format($purchasePrice, 2) . " {$stock->currency}";
            } else {
                // ไม่รู้ต้นทุน → ใช้ราคาปัจจุบันเป็นฐาน (กำไร/ขาดทุนเริ่มที่ ~0%)
                $purchasePrice    = $priceNative;
                $invested         = null;
                $investedCurrency = null;
                $detail = number_format($shares, 4) . " หุ้น (ตั้งต้นที่ราคาปัจจุบัน)";
            }
        } else {
            // โหมดจำนวนเงิน: คำนวณหุ้นจากราคา + FX ย้อนหลังวันนั้น
            $data = $request->validate([
                'invested_amount'   => 'required|numeric|min:1',
                'invested_currency' => 'required|in:THB,USD',
            ]);
            $amountNative = $this->toNativeAmount(
                (float) $data['invested_amount'],
                $data['invested_currency'],
                $stock->currency,
                $date
            );
            $shares           = $amountNative / $priceNative;
            $purchasePrice    = $priceNative;
            $invested         = (float) $data['invested_amount'];
            $investedCurrency = $data['invested_currency'];
            $detail = "ลงทุน " . number_format($invested, 0) . " {$investedCurrency} → "
                . number_format($shares, 4) . " หุ้น @ " . number_format($priceNative, 2) . " {$stock->currency}";
        }

        $this->defaultPortfolio()->items()->create([
            'stock_id'          => $stock->id,
            'invested_amount'   => $invested,
            'invested_currency' => $investedCurrency,
            'shares'            => $shares,
            'purchase_price'    => $purchasePrice,
            'purchase_date'     => $date,
        ]);

        return back()->with('success', "เพิ่ม {$stock->symbol} แล้ว — {$detail}");
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
            . "วิเคราะห์เป็นภาษาไทยให้นักลงทุนมือใหม่เข้าใจง่าย\n\n"
            . "ตอบเป็น Markdown โดยแบ่งเป็น 3 หัวข้อนี้ (ใช้ ## นำหน้าหัวข้อ):\n"
            . "## 📊 ภาพรวมความเสี่ยง\n## 🌐 การกระจายความเสี่ยง\n## 🔧 คำแนะนำปรับสมดุล\n\n"
            . "กติกาการเขียน:\n"
            . "- แต่ละหัวข้อใช้ bullet (-) 2-4 ข้อ สั้นกระชับ ข้อละ 1-2 บรรทัด\n"
            . "- เว้นบรรทัดว่างระหว่างหัวข้อ\n"
            . "- เน้นคำสำคัญด้วย **ตัวหนา** ได้\n"
            . "- ห้ามเขียนเป็นพารากราฟยาวๆ ติดกัน";

        $result = $gemini->generateText($prompt, ['maxOutputTokens' => 1024]);

        if (!$result) {
            $msg = $gemini->lastStatus === 429
                ? 'โควต้า AI ฟรีหมดสำหรับวันนี้แล้ว — ลองใหม่พรุ่งนี้ หรือเปลี่ยน Model ในหน้าตั้งค่า (เช่น gemini-2.5-flash-lite ที่โควต้าสูงกว่า)'
                : 'AI วิเคราะห์ไม่สำเร็จ ลองใหม่อีกครั้ง';
            return response()->json(['success' => false, 'message' => $msg]);
        }

        // แปลง Markdown → HTML (commonmark escape raw html ให้อยู่แล้ว ปลอดภัย)
        return response()->json([
            'success'       => true,
            'analysis_html' => Str::markdown(trim($result)),
        ]);
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

    /** ราคาปิด (สกุลหุ้น) ณ วันที่ หรือวันทำการก่อนหน้าที่ใกล้ที่สุด */
    private function historicalPrice(int $stockId, string $date): ?float
    {
        $row = StockPrice::where('stock_id', $stockId)
            ->where('date', '<=', $date)
            ->orderBy('date', 'desc')
            ->first(['close']);
        return $row ? (float) $row->close : null;
    }

    /** แปลงเงินลงทุนเป็นสกุลของหุ้น (ใช้ FX ย้อนหลังถ้าข้ามสกุล) */
    private function toNativeAmount(float $amount, string $paidCurrency, string $stockCurrency, string $date): float
    {
        if ($paidCurrency === $stockCurrency) {
            return $amount;
        }
        $fx = $this->historicalFx($date); // THB ต่อ 1 USD
        if ($paidCurrency === 'THB' && $stockCurrency === 'USD') {
            return $amount / $fx;
        }
        if ($paidCurrency === 'USD' && $stockCurrency === 'THB') {
            return $amount * $fx;
        }
        return $amount;
    }

    /** อัตราแลกเปลี่ยน USD→THB ย้อนหลัง (THB=X) ณ วันที่ — cache + fallback เรตปัจจุบัน */
    private function historicalFx(string $date): float
    {
        return Cache::remember("fx_usdthb:{$date}", now()->addDays(30), function () use ($date) {
            try {
                $ts = Carbon::parse($date);
                $resp = Http::withHeaders(['User-Agent' => 'Mozilla/5.0'])
                    ->get('https://query1.finance.yahoo.com/v8/finance/chart/THB=X', [
                        'period1'  => $ts->copy()->subDays(7)->timestamp,
                        'period2'  => $ts->copy()->addDay()->timestamp,
                        'interval' => '1d',
                    ]);
                $result = $resp->json('chart.result.0');
                $closes = $result['indicators']['quote'][0]['close'] ?? [];
                $closes = array_values(array_filter($closes, fn ($c) => $c !== null));
                if (!empty($closes)) {
                    return (float) end($closes); // close ล่าสุด <= วันที่
                }
            } catch (\Throwable $e) {
                // ตกไป fallback
            }
            return $this->exchangeRate(); // fallback เรตปัจจุบัน
        });
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
            $isUsd = !str_ends_with(strtoupper($stock->symbol), '.BK');
            $valueThb = $isUsd ? $value * $rate : $value;

            // cost basis = เงินที่ลงทุนจริง (แม่นยำ) — แปลงเป็น THB ตามสกุลที่จ่าย
            if ($item->invested_amount) {
                $investedThb = $item->invested_currency === 'USD'
                    ? $item->invested_amount * $rate
                    : $item->invested_amount;
            } else {
                // รายการเก่าที่ไม่มี invested_amount → คำนวณจาก purchase_price
                $cost = $item->purchase_price * $item->shares;
                $investedThb = $isUsd ? $cost * $rate : $cost;
            }

            $totalValueThb += $valueThb;
            $totalCostThb  += $investedThb;

            $holdings[] = [
                'id'             => $item->id,
                'symbol'         => $stock->symbol,
                'name'           => $stock->name,
                'currency'       => $stock->currency,
                'shares'         => $item->shares,
                'purchase_price' => $item->purchase_price,
                'invested_amount' => $item->invested_amount,
                'invested_currency' => $item->invested_currency,
                'purchase_date'  => $item->purchase_date?->format('d/m/Y'),
                'current_price'  => $currentPrice,
                'value'          => $value,
                'value_thb'      => $valueThb,
                'cost_thb'       => $investedThb,
                'pl_value_thb'   => $valueThb - $investedThb,
                'pl_percent'     => $investedThb > 0 ? (($valueThb - $investedThb) / $investedThb) * 100 : 0,
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
