<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\Portfolio;
use App\Services\DcaPlanService;
use App\Services\GeminiService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * PlanController (Phase 2) — หน้าแผน DCA
 * Flow: เลือกพอร์ต → ดึงสินทรัพย์ (มูลค่าตั้งต้นรายตัว) → กรอกยอด DCA แยกรายตัว + ความถี่ + วันเริ่ม → คำนวณ
 * ใช้สิทธิ์เดียวกับพอร์ต (permission:portfolio)
 */
class PlanController extends Controller
{
    public function __construct(private DcaPlanService $svc) {}

    public function index()
    {
        $user       = auth()->user();
        $plans      = $user->plans()->with('portfolio')->latest()->get();
        $portfolios = $user->portfolios()->orderBy('name')->get();

        // แผนที่กำลังดู (จาก ?plan=) — ถ้าไม่มี = โหมดสร้างใหม่
        $selected = ($id = request('plan')) ? $plans->firstWhere('id', (int) $id) : null;

        // พอร์ตที่ใช้ "ดึงสินทรัพย์" ในฟอร์ม: ?portfolio= > พอร์ตของแผนที่เลือก > พอร์ตแรก
        $formPortfolioId = (int) (request('portfolio')
            ?? $selected?->portfolio_id
            ?? $portfolios->first()?->id);
        $formPortfolio = $formPortfolioId ? $portfolios->firstWhere('id', $formPortfolioId) : null;

        // สินทรัพย์ในพอร์ตนั้น (มูลค่าตั้งต้น + CAGR) สำหรับกรอกยอด DCA รายตัว
        $formAssets = $formPortfolio ? $this->svc->portfolioAssets($formPortfolio) : [];

        return view('plan.index', [
            'plans'         => $plans,
            'portfolios'    => $portfolios,
            'selected'      => $selected,
            'formPortfolio' => $formPortfolio,
            'formAssets'    => $formAssets,
            'savedDca'      => $selected?->asset_dca ?? [],
            'savedCagr'     => $selected?->asset_cagr ?? [],
            'savedExcluded' => $selected?->asset_excluded ?? [],
            'aiHtml'        => $selected?->ai_analysis ? Str::markdown($selected->ai_analysis) : null,
        ]);
    }

    public function store(Request $request)
    {
        $data      = $this->validatePlan($request);
        $portfolio = $this->ownedPortfolio($data['portfolio_id']);
        $years     = $this->resolveYears($data);
        $days      = $this->resolveDays($data['frequency'], $request);
        $assetDca  = $this->resolveAssetDca($request);
        $assetCagr = $this->resolveAssetCagr($request);
        $excluded  = $this->resolveExcluded($request);

        $result = $this->svc->project($portfolio, $assetDca, $assetCagr, $data['frequency'], $days, $years, $data['start_date'], $excluded);

        $plan = auth()->user()->plans()->create([
            'portfolio_id'   => $portfolio->id,
            'name'           => $data['name'],
            'current_age'    => $data['current_age'] ?? null,
            'retire_age'     => $data['retire_age'] ?? null,
            'years'          => $years,
            'start_date'     => $data['start_date'],
            'frequency'      => $data['frequency'],
            'frequency_days' => $days,
            'asset_dca'      => $assetDca,
            'asset_cagr'     => $assetCagr,
            'asset_excluded' => $excluded,
            'result'         => $result,
            'computed_at'    => Carbon::now(),
        ]);

        return redirect()->route('plan.index', ['plan' => $plan->id])
            ->with('success', "สร้างแผน \"{$plan->name}\" แล้ว");
    }

    public function update(Request $request, Plan $plan)
    {
        $this->guardOwns($plan);
        $data      = $this->validatePlan($request);
        $portfolio = $this->ownedPortfolio($data['portfolio_id']);
        $years     = $this->resolveYears($data);
        $days      = $this->resolveDays($data['frequency'], $request);
        $assetDca  = $this->resolveAssetDca($request);
        $assetCagr = $this->resolveAssetCagr($request);

        // คำนวณใหม่ทุกครั้ง (ราคา/สัดส่วนพอร์ตอาจเปลี่ยน) — ล้างบทวิเคราะห์ AI เดิม
        $result = $this->svc->project($portfolio, $assetDca, $assetCagr, $data['frequency'], $days, $years, $data['start_date']);

        $plan->update([
            'portfolio_id'   => $portfolio->id,
            'name'           => $data['name'],
            'current_age'    => $data['current_age'] ?? null,
            'retire_age'     => $data['retire_age'] ?? null,
            'years'          => $years,
            'start_date'     => $data['start_date'],
            'frequency'      => $data['frequency'],
            'frequency_days' => $days,
            'asset_dca'      => $assetDca,
            'asset_cagr'     => $assetCagr,
            'asset_excluded' => $excluded,
            'result'         => $result,
            'ai_analysis'    => null,
            'computed_at'    => Carbon::now(),
        ]);

        return redirect()->route('plan.index', ['plan' => $plan->id])
            ->with('success', "อัปเดตแผน \"{$plan->name}\" แล้ว");
    }

    public function destroy(Plan $plan)
    {
        $this->guardOwns($plan);
        $name = $plan->name;
        $plan->delete();

        return redirect()->route('plan.index')->with('success', "ลบแผน \"{$name}\" แล้ว");
    }

    /**
     * AI ตีความผลแผน (AJAX) — ส่ง "ตัวเลขที่คำนวณแล้ว" ให้ Gemini วิเคราะห์
     * ย้ำ: AI ไม่คำนวณเลข แค่ตีความ/ประเมินความเสี่ยง/ให้คำแนะนำ
     */
    public function analyze(Plan $plan, GeminiService $gemini)
    {
        $this->guardOwns($plan);

        $result = $plan->result;
        if (empty($result['assets'])) {
            return response()->json(['success' => false, 'message' => 'แผนนี้ยังไม่มีสินทรัพย์ให้วิเคราะห์ (พอร์ตว่าง)']);
        }

        $t = $result['totals'];
        $meta = $result['meta'] ?? [];
        $freqLabel = $meta['frequency_label'] ?? $plan->frequency;

        $lines = [];
        foreach ($result['assets'] as $a) {
            $est = $a['cagr_estimated'] ? ' (CAGR ประมาณการ)' : '';
            $lines[] = "- {$a['symbol']} ({$a['name']}): ตั้งต้น " . number_format($a['start_value_thb'], 0) . ' บาท'
                . ', DCA ครั้งละ ' . number_format($a['dca_amount'], 0) . ' บาท'
                . ', CAGR ' . number_format($a['cagr_pct'], 1) . "%{$est}"
                . ' → คาดการณ์ ' . number_format($a['future_value_thb'], 0) . ' บาท'
                . ' (กำไร ' . number_format($a['profit_pct'], 1) . '%)';
        }
        $block = implode("\n", $lines);

        $ageNote = ($plan->current_age && $plan->retire_age)
            ? "นักลงทุนอายุ {$plan->current_age} ปี ตั้งใจเกษียณอายุ {$plan->retire_age} ปี\n"
            : '';

        $prompt = "คุณคือที่ปรึกษาการวางแผนการเงินมืออาชีพ ช่วยวิเคราะห์ \"แผน DCA\" ต่อไปนี้ "
            . "(ตัวเลขด้านล่างคำนวณด้วยสูตรผลตอบแทนทบต้นจาก CAGR ย้อนหลังมาแล้ว — คุณไม่ต้องคำนวณซ้ำ แค่ตีความและให้คำแนะนำ):\n\n"
            . $ageNote
            . "เงื่อนไข: DCA {$freqLabel} เป็นเวลา {$plan->years} ปี (เริ่ม " . ($meta['start_date'] ?? '-') . ")\n"
            . "เงินลงทุนรวมตลอดแผน: " . number_format($t['invested_thb'], 0) . " บาท\n"
            . "มูลค่าพอร์ตคาดการณ์ในอีก {$plan->years} ปี: " . number_format($t['future_value_thb'], 0) . " บาท"
            . " (กำไร " . number_format($t['profit_pct'], 1) . "%)\n\n"
            . "รายสินทรัพย์:\n{$block}\n\n"
            . "วิเคราะห์เป็นภาษาไทยให้นักลงทุนเข้าใจง่าย ตอบเป็น Markdown แบ่ง 4 หัวข้อ (ใช้ ## นำหน้า):\n"
            . "## 🎯 สรุปแผนนี้\n## ⚖️ ความเสี่ยง & การกระจาย\n## 💡 ข้อแนะนำปรับแผน\n## ⚠️ ข้อควรระวัง\n\n"
            . "กติกา: แต่ละหัวข้อใช้ bullet (-) 2-4 ข้อ สั้นกระชับ, เน้นด้วย **ตัวหนา** ได้, "
            . "ในหัวข้อข้อควรระวังให้ย้ำว่าเป็นการประมาณการจากผลตอบแทนอดีต ไม่ใช่การรับประกัน";

        $markdown = $gemini->generateText($prompt, ['maxOutputTokens' => 4096]);

        if (!$markdown) {
            $msg = $gemini->lastStatus === 429
                ? 'โควต้า AI ฟรีหมดสำหรับวันนี้แล้ว — ลองใหม่พรุ่งนี้ หรือเปลี่ยน Model ในหน้าตั้งค่า'
                : 'AI วิเคราะห์ไม่สำเร็จ ลองใหม่อีกครั้ง';
            return response()->json(['success' => false, 'message' => $msg]);
        }

        $markdown = trim($markdown);
        $plan->update(['ai_analysis' => $markdown]);

        return response()->json([
            'success'       => true,
            'analysis_html' => Str::markdown($markdown),
            'analyzed_at'   => now()->format('d/m/Y H:i'),
        ]);
    }

    // ───────────────────────── helpers ─────────────────────────

    private function validatePlan(Request $request): array
    {
        return $request->validate([
            'name'         => 'required|string|max:100',
            'portfolio_id' => 'required|exists:portfolios,id',
            'current_age'  => 'nullable|integer|min:1|max:120',
            'retire_age'   => 'nullable|integer|min:1|max:120',
            'years'        => 'nullable|integer|min:1|max:80',
            'start_date'   => 'required|date',
            'frequency'    => 'required|in:daily,weekly,monthly,custom,once',
            'frequency_days_raw' => 'nullable|string|max:200',
            'amounts'      => 'nullable|array',
            'amounts.*'    => 'nullable|numeric|min:0',
            'cagr'         => 'nullable|array',
            'cagr.*'       => 'nullable|numeric',
            'excluded'     => 'nullable|array',
            'excluded.*'   => 'string',
        ]);
    }

    /** จำนวนปี: ใช้ years ที่กรอกตรงๆ ก่อน ไม่งั้นคิดจาก (retire - current) */
    private function resolveYears(array $data): int
    {
        if (!empty($data['years'])) {
            return (int) $data['years'];
        }
        if (!empty($data['current_age']) && !empty($data['retire_age']) && $data['retire_age'] > $data['current_age']) {
            return (int) $data['retire_age'] - (int) $data['current_age'];
        }
        abort(422, 'ระบุจำนวนปี หรืออายุปัจจุบัน + อายุเกษียณ (เกษียณต้องมากกว่าปัจจุบัน)');
    }

    /** แปลง input วันที่ของเดือน "4, 20" → [4,20] (เฉพาะ frequency=custom) — กรอง 1-31 + ไม่ซ้ำ */
    private function resolveDays(string $frequency, Request $request): ?array
    {
        if ($frequency !== 'custom') {
            return null;
        }
        $raw  = (string) $request->input('frequency_days_raw', '');
        $days = collect(preg_split('/[,\s]+/', $raw, -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn ($d) => (int) $d)
            ->filter(fn ($d) => $d >= 1 && $d <= 31)
            ->unique()
            ->sort()
            ->values()
            ->all();

        if (empty($days)) {
            abort(422, 'เลือก "วันที่กำหนดเอง" ต้องระบุวันที่ของเดือนอย่างน้อย 1 วัน (เช่น 4, 20)');
        }
        return $days;
    }

    /** ยอด DCA รายสินทรัพย์ { symbol: บาท } — ตัดค่าว่าง/0 ทิ้ง */
    private function resolveAssetDca(Request $request): array
    {
        $amounts = (array) $request->input('amounts', []);
        $out = [];
        foreach ($amounts as $symbol => $amount) {
            $val = (float) $amount;
            if ($val > 0) {
                $out[$symbol] = round($val, 2);
            }
        }
        return $out;
    }

    /** CAGR ที่ผู้ใช้ปรับเองรายสินทรัพย์ { symbol: % } — เก็บเฉพาะที่กรอกค่า (numeric) */
    private function resolveAssetCagr(Request $request): array
    {
        $cagr = (array) $request->input('cagr', []);
        $out = [];
        foreach ($cagr as $symbol => $pct) {
            if ($pct !== null && $pct !== '' && is_numeric($pct)) {
                $out[$symbol] = round((float) $pct, 2);
            }
        }
        return $out;
    }

    /** สินทรัพย์ที่เอาออกจากแผน (list ของ symbol) — unique */
    private function resolveExcluded(Request $request): array
    {
        return collect((array) $request->input('excluded', []))
            ->filter(fn ($s) => is_string($s) && $s !== '')
            ->unique()
            ->values()
            ->all();
    }

    /** กัน IDOR: พอร์ตต้องเป็นของ user ปัจจุบัน */
    private function ownedPortfolio(int $portfolioId): Portfolio
    {
        return auth()->user()->portfolios()->findOrFail($portfolioId);
    }

    /** กัน IDOR: แผนต้องเป็นของ user ปัจจุบัน */
    private function guardOwns(Plan $plan): void
    {
        if ($plan->user_id !== auth()->id()) {
            abort(404);
        }
    }
}
