<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesUserStocks;
use App\Models\Portfolio;
use App\Models\PortfolioItem;
use App\Models\Stock;
use App\Services\GeminiService;
use App\Services\PortfolioService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PortfolioController extends Controller
{
    use ScopesUserStocks;

    public function __construct(private PortfolioService $svc) {}

    private const PER_PAGE = 10;

    public function index()
    {
        $portfolio = $this->currentPortfolio();
        $stocks    = $this->userStocks()->orderBy('symbol')->get(); // เลือกเพิ่มได้เฉพาะหุ้นที่ติดตาม
        $data      = $this->svc->buildHoldings($portfolio);

        // ledger ธุรกรรม (รายการถือครอง) แบ่งหน้า
        $holdingsPage = $this->paginateHoldings($data['transactions'], 1);

        // ผลวิเคราะห์ AI ล่าสุดของพอร์ตนี้ (ถ้ามี)
        $latest = $portfolio->latestHealthCheck;
        $latestHealthHtml = $latest ? Str::markdown($latest->analysis) : null;
        $latestHealthAt   = $latest ? $latest->created_at->format('d/m/Y H:i') : null;

        return view('portfolio.index', array_merge($data, [
            'portfolio'        => $portfolio,
            'portfolios'       => auth()->user()->portfolios()->orderBy('name')->get(),
            'stocks'           => $stocks,
            'rate'             => $this->svc->currentFx(),
            'holdingsPage'     => $holdingsPage,
            'latestHealthHtml' => $latestHealthHtml,
            'latestHealthAt'   => $latestHealthAt,
        ]));
    }

    /** สร้างพอร์ตใหม่ + ตั้งเป็นพอร์ตที่เลือก */
    public function storePortfolio(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
        ]);
        $portfolio = $request->user()->portfolios()->create(['name' => $validated['name']]);
        session(['active_portfolio_id' => $portfolio->id]);

        return back()->with('success', "สร้างพอร์ต \"{$portfolio->name}\" แล้ว");
    }

    /** เปลี่ยนชื่อพอร์ต */
    public function renamePortfolio(Request $request, Portfolio $portfolio)
    {
        $this->guardOwnsPortfolio($portfolio);
        $validated = $request->validate([
            'name' => 'required|string|max:100',
        ]);
        $portfolio->update(['name' => $validated['name']]);

        return back()->with('success', "เปลี่ยนชื่อพอร์ตเป็น \"{$portfolio->name}\"");
    }

    /** สลับพอร์ตที่กำลังดู */
    public function switchPortfolio(Portfolio $portfolio)
    {
        $this->guardOwnsPortfolio($portfolio);
        session(['active_portfolio_id' => $portfolio->id]);
        return back()->with('success', "เปลี่ยนไปพอร์ต \"{$portfolio->name}\"");
    }

    /** ลบพอร์ต (กันลบจนเหลือ 0) */
    public function destroyPortfolio(Portfolio $portfolio)
    {
        $this->guardOwnsPortfolio($portfolio);
        if (auth()->user()->portfolios()->count() <= 1) {
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
        $data = $this->svc->buildHoldings($this->currentPortfolio());
        $holdingsPage = $this->paginateHoldings($data['transactions'], (int) $request->input('page', 1));

        return view('portfolio._holdings', compact('holdingsPage'))->render();
    }

    public function storeItem(Request $request)
    {
        $request->validate([
            'type'          => 'required|in:buy,sell',
            'stock_id'      => 'required|exists:stocks,id',
            'purchase_date' => 'nullable|date|before_or_equal:today',
            'fx_rate'       => 'nullable|numeric|min:1|max:200',
        ]);
        $fxRate = $request->filled('fx_rate') ? (float) $request->input('fx_rate') : null;

        // เลือกได้เฉพาะหุ้นที่ user ติดตาม (กัน inject stock_id ของคนอื่น)
        $stock = $this->userStocks()->findOrFail($request->input('stock_id'));
        $date  = $request->input('purchase_date') ?: now()->toDateString();

        // ── ขาย: หุ้นที่ขาย + ราคาขาย + สกุล (หักออกจากพอร์ต) ──
        if ($request->input('type') === 'sell') {
            $data = $request->validate([
                'shares'        => 'required|numeric|min:0.0000001',
                'sell_price'    => 'required|numeric|min:0.0000001',
                'sell_currency' => 'required|in:THB,USD',
            ]);
            $shares = (float) $data['shares'];
            $price  = (float) $data['sell_price'];
            $this->currentPortfolio()->items()->create([
                'type'              => 'sell',
                'stock_id'          => $stock->id,
                'shares'            => $shares,
                'purchase_price'    => $price,
                'invested_amount'   => round($price * $shares, 2),       // เงินที่ได้จากการขาย (proceeds)
                'invested_currency' => $data['sell_currency'],
                'fx_rate'           => $fxRate,
                'purchase_date'     => $date,
            ]);
            return back()->with('success', "บันทึกการขาย {$stock->symbol} {$shares} หุ้น แล้ว");
        }

        // ── ซื้อ (เดิม) ──
        $request->validate(['mode' => 'required|in:amount,shares']);
        $fields = $this->computeItemFields($stock, $request->input('mode'), $date, $request);
        if (isset($fields['error'])) {
            return back()->with('error', $fields['error']);
        }

        $this->currentPortfolio()->items()->create([
            'type'              => 'buy',
            'stock_id'          => $stock->id,
            'invested_amount'   => $fields['invested'],
            'invested_currency' => $fields['investedCurrency'],
            'shares'            => $fields['shares'],
            'purchase_price'    => $fields['purchasePrice'],
            'fx_rate'           => $fxRate,
            'purchase_date'     => $date,
        ]);

        return back()->with('success', "เพิ่ม {$stock->symbol} แล้ว — {$fields['detail']}");
    }

    /** แก้ไขรายการถือครอง (หุ้นเดิม เปลี่ยนเงิน/หุ้น/วันที่ได้) */
    public function updateItem(Request $request, PortfolioItem $item)
    {
        $this->guardOwnsItem($item);
        $request->validate([
            'mode'          => 'required|in:amount,shares',
            'purchase_date' => 'nullable|date|before_or_equal:today',
            'fx_rate'       => 'nullable|numeric|min:1|max:200',
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
            'fx_rate'           => $request->filled('fx_rate') ? (float) $request->input('fx_rate') : null,
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
        $priceNative = $this->svc->historicalPrice($stock->id, $date);
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
            $amountNative     = $this->svc->toNativeAmount((float) $data['invested_amount'], $data['invested_currency'], $stock->currency, $date);
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
        $this->guardOwnsItem($item);
        $item->delete();
        return back()->with('success', 'ลบหุ้นออกจากพอร์ตแล้ว');
    }

    /**
     * AI ตรวจสุขภาพพอร์ต (AJAX) — วิเคราะห์การกระจุกตัว + คำแนะนำ rebalance
     */
    public function healthCheck(GeminiService $gemini)
    {
        $portfolio = $this->currentPortfolio();
        $data = $this->svc->buildHoldings($portfolio);

        if (empty($data['positions'])) {
            return response()->json(['success' => false, 'message' => 'ยังไม่มีหุ้นในพอร์ต']);
        }

        // สรุป position สุทธิให้ AI (สัดส่วนเป็น % ของมูลค่ารวมในสกุล THB)
        $lines = [];
        foreach ($data['positions'] as $p) {
            $lines[] = "- {$p['symbol']} ({$p['name']}): สัดส่วน " . number_format($p['allocation'], 1)
                . "% มูลค่า " . number_format($p['value_thb'], 0) . " บาท"
                . " กำไร/ขาดทุน " . number_format($p['unrealized_pl_pct'], 1) . "%";
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

        // 4096: เผื่อ thinking model ใช้ token คิดก่อน + markdown ผลลัพธ์ (1024 เดิมน้อยไป → text ว่าง)
        $result = $gemini->generateText($prompt, ['maxOutputTokens' => 4096]);

        if (!$result) {
            $msg = $gemini->lastStatus === 429
                ? 'โควต้า AI ฟรีหมดสำหรับวันนี้แล้ว — ลองใหม่พรุ่งนี้ หรือเปลี่ยน Model ในหน้าตั้งค่า (เช่น gemini-2.5-flash-lite ที่โควต้าสูงกว่า)'
                : 'AI วิเคราะห์ไม่สำเร็จ ลองใหม่อีกครั้ง';
            return response()->json(['success' => false, 'message' => $msg]);
        }

        $markdown = trim($result);

        // บันทึกผลทุกครั้งที่วิเคราะห์ (เก็บประวัติ — แสดงเฉพาะล่าสุดของพอร์ตนี้)
        $portfolio->healthChecks()->create(['analysis' => $markdown]);

        // แปลง Markdown → HTML (commonmark escape raw html ให้อยู่แล้ว ปลอดภัย)
        return response()->json([
            'success'       => true,
            'analysis_html' => Str::markdown($markdown),
            'analyzed_at'   => now()->format('d/m/Y H:i'),
        ]);
    }

    // ───────────────────────── helpers ─────────────────────────

    /** พอร์ตที่กำลังเลือกอยู่ (จาก session) — จำกัดเฉพาะของ user ปัจจุบัน */
    private function currentPortfolio(): Portfolio
    {
        $user = auth()->user();
        $id   = session('active_portfolio_id');

        // session ต้องชี้พอร์ตที่เป็นของ user เท่านั้น (กันค้าง id ของคนอื่น)
        if ($id && ($p = $user->portfolios()->find($id))) {
            return $p;
        }
        return $user->portfolios()->orderBy('id')->first()
            ?? $user->portfolios()->create(['name' => 'พอร์ตของฉัน', 'description' => 'พอร์ตการลงทุนหลัก']);
    }

    /** กัน IDOR: พอร์ตต้องเป็นของ user ปัจจุบัน */
    private function guardOwnsPortfolio(Portfolio $portfolio): void
    {
        if ($portfolio->user_id !== auth()->id()) {
            abort(404);
        }
    }

    /** กัน IDOR: รายการถือครองต้องอยู่ในพอร์ตของ user ปัจจุบัน */
    private function guardOwnsItem(PortfolioItem $item): void
    {
        if ($item->portfolio?->user_id !== auth()->id()) {
            abort(404);
        }
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
}
