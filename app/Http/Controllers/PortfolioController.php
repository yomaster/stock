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

    private const PER_PAGE = 10;

    public function index()
    {
        $portfolio = $this->currentPortfolio();
        $stocks    = Stock::orderBy('symbol')->get();
        $data      = $this->buildHoldings($portfolio);

        // สัดส่วนสำหรับกราฟ: group ตาม symbol (รวมทุก lot ของหุ้นเดียวกัน)
        $allocation   = $this->groupBySymbol($data['holdings'], $data['total_value_thb']);
        $holdingsPage = $this->paginateHoldings($data['holdings'], 1);

        return view('portfolio.index', array_merge($data, [
            'portfolio'    => $portfolio,
            'portfolios'   => Portfolio::orderBy('name')->get(),
            'stocks'       => $stocks,
            'rate'         => $this->currentFx(),
            'allocation'   => $allocation,
            'holdingsPage' => $holdingsPage,
        ]));
    }

    /** สร้างพอร์ตใหม่ + ตั้งเป็นพอร์ตที่เลือก */
    public function storePortfolio(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
        ]);
        $portfolio = Portfolio::create(['name' => $validated['name']]);
        session(['active_portfolio_id' => $portfolio->id]);

        return back()->with('success', "สร้างพอร์ต \"{$portfolio->name}\" แล้ว");
    }

    /** สลับพอร์ตที่กำลังดู */
    public function switchPortfolio(Portfolio $portfolio)
    {
        session(['active_portfolio_id' => $portfolio->id]);
        return back()->with('success', "เปลี่ยนไปพอร์ต \"{$portfolio->name}\"");
    }

    /** ลบพอร์ต (กันลบจนเหลือ 0) */
    public function destroyPortfolio(Portfolio $portfolio)
    {
        if (Portfolio::count() <= 1) {
            return back()->with('error', 'ต้องมีอย่างน้อย 1 พอร์ต — ลบพอร์ตสุดท้ายไม่ได้');
        }
        $name = $portfolio->name;
        $portfolio->delete(); // cascade ลบ items ด้วย
        session()->forget('active_portfolio_id');

        return back()->with('success', "ลบพอร์ต \"{$name}\" แล้ว");
    }

    /** AJAX: ตารางรายการถือครองแบบแบ่งหน้า */
    public function holdings(Request $request)
    {
        $data = $this->buildHoldings($this->currentPortfolio());
        $holdingsPage = $this->paginateHoldings($data['holdings'], (int) $request->input('page', 1));

        return view('portfolio._holdings', compact('holdingsPage'))->render();
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

        $fields = $this->computeItemFields($stock, $request->input('mode'), $date, $request);
        if (isset($fields['error'])) {
            return back()->with('error', $fields['error']);
        }

        $this->currentPortfolio()->items()->create([
            'stock_id'          => $stock->id,
            'invested_amount'   => $fields['invested'],
            'invested_currency' => $fields['investedCurrency'],
            'shares'            => $fields['shares'],
            'purchase_price'    => $fields['purchasePrice'],
            'purchase_date'     => $date,
        ]);

        return back()->with('success', "เพิ่ม {$stock->symbol} แล้ว — {$fields['detail']}");
    }

    /** แก้ไขรายการถือครอง (หุ้นเดิม เปลี่ยนเงิน/หุ้น/วันที่ได้) */
    public function updateItem(Request $request, PortfolioItem $item)
    {
        $request->validate([
            'mode'          => 'required|in:amount,shares',
            'purchase_date' => 'nullable|date|before_or_equal:today',
        ]);

        $stock = $item->stock;
        $date  = $request->input('purchase_date') ?: now()->toDateString();

        $fields = $this->computeItemFields($stock, $request->input('mode'), $date, $request);
        if (isset($fields['error'])) {
            return back()->with('error', $fields['error']);
        }

        $item->update([
            'invested_amount'   => $fields['invested'],
            'invested_currency' => $fields['investedCurrency'],
            'shares'            => $fields['shares'],
            'purchase_price'    => $fields['purchasePrice'],
            'purchase_date'     => $date,
        ]);

        return back()->with('success', "แก้ไข {$stock->symbol} แล้ว — {$fields['detail']}");
    }

    /**
     * คำนวณ shares/ราคา/เงินลงทุน จาก input (ใช้ร่วม store + update)
     * คืน ['error'=>...] ถ้าไม่มีราคา หรือ ['shares','purchasePrice','invested','investedCurrency','detail']
     */
    private function computeItemFields(Stock $stock, string $mode, string $date, Request $request): array
    {
        $priceNative = $this->historicalPrice($stock->id, $date);
        if (!$priceNative) {
            return ['error' => "ไม่มีข้อมูลราคา {$stock->symbol} ณ วันที่เลือก — ลองวันหลังจากนี้ หรืออัปเดตข้อมูลหุ้นก่อน"];
        }

        if ($mode === 'shares') {
            $data = $request->validate([
                'shares'   => 'required|numeric|min:0.0000001',
                'avg_cost' => 'nullable|numeric|min:0',
            ]);
            $shares = (float) $data['shares'];

            if (!empty($data['avg_cost'])) {
                $purchasePrice    = (float) $data['avg_cost'];
                $invested         = round($purchasePrice * $shares, 2);
                $investedCurrency = $stock->currency;
                $detail = rtrim(rtrim(number_format($shares, 7), '0'), '.') . " หุ้น @ ต้นทุน " . number_format($purchasePrice, 2) . " {$stock->currency}";
            } else {
                $purchasePrice    = $priceNative;
                $invested         = null;
                $investedCurrency = null;
                $detail = rtrim(rtrim(number_format($shares, 7), '0'), '.') . " หุ้น (ตั้งต้นที่ราคาปัจจุบัน)";
            }
        } else {
            $data = $request->validate([
                'invested_amount'   => 'required|numeric|min:1',
                'invested_currency' => 'required|in:THB,USD',
            ]);
            $amountNative     = $this->toNativeAmount((float) $data['invested_amount'], $data['invested_currency'], $stock->currency, $date);
            $shares           = $amountNative / $priceNative;
            $purchasePrice    = $priceNative;
            $invested         = (float) $data['invested_amount'];
            $investedCurrency = $data['invested_currency'];
            $detail = "ลงทุน " . number_format($invested, 0) . " {$investedCurrency} → "
                . rtrim(rtrim(number_format($shares, 7), '0'), '.') . " หุ้น @ " . number_format($priceNative, 2) . " {$stock->currency}";
        }

        return compact('shares', 'purchasePrice', 'invested', 'investedCurrency', 'detail');
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
        $portfolio = $this->currentPortfolio();
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

    /** พอร์ตที่กำลังเลือกอยู่ (จาก session) — fallback พอร์ตแรก/สร้างใหม่ */
    private function currentPortfolio(): Portfolio
    {
        $id = session('active_portfolio_id');
        if ($id && ($p = Portfolio::find($id))) {
            return $p;
        }
        return Portfolio::orderBy('id')->first()
            ?? Portfolio::create(['name' => 'พอร์ตของฉัน', 'description' => 'พอร์ตการลงทุนหลัก']);
    }

    /** ค่า fallback เมื่อดึงเรทสดไม่ได้ */
    private function exchangeRate(): float
    {
        return (float) $this->settings->get('general.default_exchange_rate', 33);
    }

    /** อัตราแลกเปลี่ยน USD→THB สด (วันนี้) จาก Yahoo THB=X — cache 6 ชม. fallback ค่า setting */
    private function currentFx(): float
    {
        return Cache::remember('fx_usdthb_current', now()->addHours(6), function () {
            try {
                $resp = Http::withHeaders(['User-Agent' => 'Mozilla/5.0'])
                    ->get('https://query1.finance.yahoo.com/v8/finance/chart/THB=X', [
                        'interval' => '1d', 'range' => '1d',
                    ]);
                $price = $resp->json('chart.result.0.meta.regularMarketPrice');
                if ($price) {
                    return (float) $price;
                }
            } catch (\Throwable $e) {
                // ตกไป fallback
            }
            return $this->exchangeRate();
        });
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

    /** รวม holdings ตาม symbol (สำหรับกราฟสัดส่วน) — รวมทุก lot ของหุ้นเดียวกัน */
    private function groupBySymbol(array $holdings, float $totalValueThb): array
    {
        $grouped = [];
        foreach ($holdings as $h) {
            $sym = $h['symbol'];
            if (!isset($grouped[$sym])) {
                $grouped[$sym] = ['symbol' => $sym, 'name' => $h['name'], 'value_thb' => 0];
            }
            $grouped[$sym]['value_thb'] += $h['value_thb'];
        }
        foreach ($grouped as &$g) {
            $g['allocation'] = $totalValueThb > 0 ? ($g['value_thb'] / $totalValueThb) * 100 : 0;
        }
        unset($g);

        usort($grouped, fn ($a, $b) => $b['value_thb'] <=> $a['value_thb']);
        return array_values($grouped);
    }

    /** แบ่งหน้า holdings (คำนวณใน PHP แล้ว slice) */
    private function paginateHoldings(array $holdings, int $page): array
    {
        $total = count($holdings);
        $pages = (int) max(1, ceil($total / self::PER_PAGE));
        $page  = max(1, min($page, $pages));
        $items = array_slice($holdings, ($page - 1) * self::PER_PAGE, self::PER_PAGE);

        return ['items' => $items, 'page' => $page, 'pages' => $pages, 'total' => $total];
    }

    /**
     * คำนวณ holdings: มูลค่าปัจจุบัน, ทุน, กำไร/ขาดทุน, สัดส่วน (แปลง USD→THB ด้วย rate)
     */
    private function buildHoldings(Portfolio $portfolio): array
    {
        $rate = $this->currentFx(); // เรทสดวันนี้ สำหรับแปลงมูลค่าปัจจุบัน USD→THB
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
                'purchase_date_raw' => $item->purchase_date?->format('Y-m-d'),
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
