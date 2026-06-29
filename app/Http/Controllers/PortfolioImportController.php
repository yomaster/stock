<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesUserStocks;
use App\Jobs\FetchFundNavJob;
use App\Models\Portfolio;
use App\Models\PortfolioItem;
use App\Models\SecFund;
use App\Models\Stock;
use App\Services\GeminiService;
use App\Services\SecFundApi;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

/**
 * นำเข้ารายการถือครองจากภาพหน้าจอโบรก (Gemini Vision OCR)
 * parse: อ่านภาพ → preview · confirm: บันทึกเข้าพอร์ต
 */
class PortfolioImportController extends Controller
{
    use ScopesUserStocks;

    /** อ่านภาพ → คืนรายการที่อ่านได้ + สถานะซ้ำ + หุ้นใหม่ที่เพิ่มให้ */
    public function parse(Request $request, GeminiService $gemini)
    {
        $request->validate([
            'images'   => 'required|array|max:8',
            'images.*' => 'image|max:5120', // ≤5MB/ภาพ
        ]);

        // เตรียมรูปเป็น base64
        $images = [];
        foreach ($request->file('images') as $file) {
            $images[] = [
                'mime' => $file->getMimeType(),
                'data' => base64_encode(file_get_contents($file->getRealPath())),
            ];
        }

        $raw = $gemini->generateFromImages($this->prompt(), $images);
        if ($raw === null) {
            $msg = $gemini->lastStatus === 429
                ? 'โควต้า AI หมดสำหรับวันนี้ — ลองใหม่พรุ่งนี้'
                : 'อ่านภาพไม่สำเร็จ ลองใหม่อีกครั้ง หรือใช้ภาพที่ชัดกว่า';
            return response()->json(['success' => false, 'message' => $msg], 422);
        }

        $list = $this->decodeJson($raw);
        if (!is_array($list)) {
            return response()->json(['success' => false, 'message' => 'อ่านภาพไม่เข้าใจ ลองใช้ภาพรายการที่ชัดเจน'], 422);
        }

        $portfolio = $this->currentPortfolio();
        $user      = $request->user();
        $rows      = [];
        $newStocks = [];

        foreach ($list as $r) {
            $type     = (($r['type'] ?? 'buy') === 'sell') ? 'sell' : 'buy';
            $symbol   = strtoupper(trim($r['symbol'] ?? ''));
            $shares   = (float) ($r['shares'] ?? 0);
            $price    = (float) ($r['price'] ?? 0);
            $amount   = (float) ($r['amount'] ?? 0);
            $currency = strtoupper(trim($r['currency'] ?? ''));
            $datetime = $this->parseDatetime($r['datetime'] ?? null);
            $note     = trim((string) ($r['note'] ?? '')); // ที่มา เช่น "สับเปลี่ยนจาก X"
            if ($symbol === '' || $shares <= 0) {
                continue; // ข้อมูลไม่ครบ → ข้าม
            }

            $fxRate = isset($r['fx_rate']) && $r['fx_rate'] ? (float) $r['fx_rate'] : null;

            // หาสินทรัพย์ + เพิ่มให้อัตโนมัติถ้ายังไม่ติดตาม (รองรับทั้งหุ้น Yahoo และกองทุน SEC)
            [$stock, $wasNew] = $this->ensureTracked($user, $symbol);
            if (!$stock) {
                $rows[] = $this->row($type, $symbol, null, $shares, $price, $amount, $currency, $fxRate, $datetime, 'invalid', $note);
                continue;
            }
            if ($wasNew) {
                $newStocks[$stock->symbol] = true;
            }
            if (!in_array($currency, ['THB', 'USD'], true)) {
                $currency = $stock->currency; // เผื่ออ่านสกุลไม่ได้ → ใช้สกุลของสินทรัพย์
            }

            // เช็คซ้ำ: สินทรัพย์ + วันที่ + จำนวนหน่วย (จับได้ทุกกรณี รวม item เดิมที่ไม่มีเวลา)
            $rows[] = $this->row($type, $stock->symbol, $stock->id, $shares, $price, $amount, $currency, $fxRate, $datetime,
                $this->isDuplicate($portfolio, $stock->id, $datetime, $shares) ? 'duplicate' : 'new', $note);
        }

        return response()->json([
            'success'    => true,
            'rows'       => $rows,
            'new_stocks' => array_keys($newStocks),
            'currency'   => null,
        ]);
    }

    /** บันทึกรายการที่เลือกเข้าพอร์ต */
    public function confirm(Request $request)
    {
        $data = $request->validate([
            'rows'              => 'required|array|min:1',
            'rows.*.type'       => 'nullable|in:buy,sell',
            'rows.*.stock_id'   => 'required|integer',
            'rows.*.shares'     => 'required|numeric|min:0.0000001',
            'rows.*.price'      => 'nullable|numeric|min:0',
            'rows.*.amount'     => 'nullable|numeric|min:0',
            'rows.*.currency'   => 'nullable|in:THB,USD',
            'rows.*.fx_rate'    => 'nullable|numeric|min:1|max:200',
            'rows.*.datetime'   => 'nullable|date',
            'rows.*.note'       => 'nullable|string|max:255',
        ]);

        $portfolio = $this->currentPortfolio();
        $trackedIds = $this->userStocks()->pluck('stocks.id')->all();

        $inserted = 0;
        $skipped  = 0;

        foreach ($data['rows'] as $r) {
            $stock = Stock::find($r['stock_id']);
            // กัน inject: ต้องเป็นหุ้นที่ user ติดตาม
            if (!$stock || !in_array($stock->id, $trackedIds)) {
                $skipped++;
                continue;
            }

            $shares     = (float) $r['shares'];
            $price      = (float) ($r['price'] ?? 0);
            $amount     = (float) ($r['amount'] ?? 0);
            $currency   = in_array($r['currency'] ?? '', ['THB', 'USD'], true) ? $r['currency'] : $stock->currency;
            $executedAt = !empty($r['datetime']) ? Carbon::parse($r['datetime']) : null;

            // กันซ้ำอีกชั้น: หุ้น + วันที่ + จำนวนหุ้น
            if ($this->isDuplicate($portfolio, $stock->id, $executedAt, $shares)) {
                $skipped++;
                continue;
            }

            $portfolio->items()->create([
                'type'              => (($r['type'] ?? 'buy') === 'sell') ? 'sell' : 'buy',
                'stock_id'          => $stock->id,
                'shares'            => $shares,
                'purchase_price'    => $price ?: ($amount && $shares ? $amount / $shares : 0),
                // เก็บมูลค่า + สกุลที่จ่าย/ได้รับจริง → พอร์ตคำนวณ FX ตามวันที่ให้เอง
                'invested_amount'   => $amount > 0 ? $amount : round($price * $shares, 2),
                'invested_currency' => $currency,
                'fx_rate'           => (isset($r['fx_rate']) && $r['fx_rate']) ? (float) $r['fx_rate'] : null,
                'purchase_date'     => $executedAt ? $executedAt->toDateString() : now()->toDateString(),
                'executed_at'       => $executedAt,
                'note'              => !empty($r['note']) ? trim($r['note']) : null,
            ]);
            $inserted++;
        }

        $message = "เพิ่ม {$inserted} รายการเข้าพอร์ตแล้ว" . ($skipped ? " (ข้าม {$skipped} ที่ซ้ำ/ไม่ถูกต้อง)" : '');
        // flash ไว้ให้ toast โชว์หลัง reload (JS เรียก location.reload)
        $request->session()->flash('success', $message);

        return response()->json([
            'success'  => true,
            'inserted' => $inserted,
            'skipped'  => $skipped,
            'message'  => $message,
        ]);
    }

    // ───────────────────────── helpers ─────────────────────────

    /** หาหุ้นในระบบ + เพิ่มให้อัตโนมัติถ้ายังไม่ติดตาม — คืน [Stock|null, wasNew] */
    private function ensureTracked($user, string $symbol): array
    {
        // มีใน catalog แล้ว → แค่ attach ถ้ายังไม่ติดตาม
        $stock = Stock::where('symbol', $symbol)->first()
            ?? Stock::where('symbol', $symbol . '.BK')->first();

        if ($stock) {
            if (!$user->stocks()->whereKey($stock->id)->exists()) {
                $user->stocks()->attach($stock->id);
                return [$stock, true];
            }
            return [$stock, false];
        }

        // ลองจับคู่กองทุนใน catalog SEC ก่อนยิง Yahoo (กองทุนไทยไม่มีบน Yahoo)
        [$fund, $fundNew] = $this->ensureFundTracked($user, $symbol);
        if ($fund) {
            return [$fund, $fundNew];
        }

        // ยังไม่มี → ดึงจาก Yahoo (ลองตามพิมพ์ → .BK)
        Artisan::call('app:fetch-stock-data', ['symbol' => $symbol, '--years' => 5]);
        $stock = Stock::where('symbol', $symbol)->whereHas('prices')->first();
        if (!$stock && !str_ends_with($symbol, '.BK')) {
            Artisan::call('app:fetch-stock-data', ['symbol' => $symbol . '.BK', '--years' => 5]);
            $stock = Stock::where('symbol', $symbol . '.BK')->whereHas('prices')->first();
        }
        Stock::where('symbol', $symbol)->whereDoesntHave('prices')->delete(); // กันขยะ

        if ($stock) {
            $user->stocks()->syncWithoutDetaching([$stock->id]);
            return [$stock, true];
        }
        return [null, false];
    }

    /**
     * จับคู่กองทุนจาก catalog SEC + เพิ่มให้อัตโนมัติ — คืน [Stock|null, wasNew]
     * statement ใช้ชื่อระดับ class (เช่น K-GOLD-A(D)) แต่ catalog เก็บระดับ fund (K-GOLD)
     * → จับคู่ตรงเป๊ะก่อน ไม่งั้นเอา proj_abbr_name ที่เป็น prefix ยาวสุดของชื่อจาก statement
     */
    private function ensureFundTracked($user, string $symbol): array
    {
        $fund = SecFund::where('proj_abbr_name', $symbol)->first()
            ?? SecFund::whereRaw("? LIKE CONCAT(proj_abbr_name, '%')", [$symbol])
                ->orderByRaw('LENGTH(proj_abbr_name) DESC')
                ->first();
        if (!$fund) {
            return [null, false];
        }

        // มี Stock ของกองนี้แล้ว → แค่ attach
        $stock = Stock::where('sec_proj_id', $fund->proj_id)->first();
        if ($stock) {
            $user->stocks()->syncWithoutDetaching([$stock->id]);
            return [$stock, false];
        }

        // สร้างใหม่ + สั่งดึง NAV ย้อนหลังผ่าน queue (เหมือน FundManageController)
        $api      = app(SecFundApi::class);
        $navClass = $api->hasDailyInfoKey() ? $api->pickNavClass($fund->proj_id) : null;

        $stock = Stock::create([
            'symbol'         => strtoupper($fund->proj_abbr_name),
            'name'           => $fund->proj_name_th ?: $fund->proj_abbr_name,
            'currency'       => 'THB',
            'exchange'       => 'SEC_TH',
            'type'           => 'MUTUALFUND',
            'asset_category' => 'fund',
            'sec_proj_id'    => $fund->proj_id,
            'sec_nav_class'  => $navClass,
        ]);
        $user->stocks()->syncWithoutDetaching([$stock->id]);

        if ($navClass) {
            FetchFundNavJob::dispatch($stock->id, $fund->proj_id, $navClass, now()->subYears(5)->format('Y-m-d'));
        }
        return [$stock, true];
    }

    private function row(string $type, string $symbol, ?int $stockId, float $shares, float $price, float $amount, string $currency, ?float $fxRate, ?Carbon $dt, string $status, string $note = ''): array
    {
        return [
            'type'     => $type, // buy | sell
            'symbol'   => $symbol,
            'stock_id' => $stockId,
            'shares'   => $shares,
            'price'    => $price,
            'amount'   => $amount,
            'currency' => $currency,
            'fx_rate'  => $fxRate,
            'datetime' => $dt?->format('Y-m-d H:i:s'),
            'note'     => $note, // ที่มา (สับเปลี่ยน) — โชว์ใน preview + เก็บลง DB
            'status'   => $status, // new | duplicate | invalid
        ];
    }

    /** เช็คซ้ำ: มี item หุ้นนี้ + วันเดียวกัน + จำนวนหุ้นเท่ากัน อยู่แล้วไหม (จับ item เดิมที่ไม่มีเวลาได้ด้วย) */
    private function isDuplicate(Portfolio $portfolio, int $stockId, ?Carbon $dt, float $shares): bool
    {
        if (!$dt) {
            return false;
        }
        return $portfolio->items()
            ->where('stock_id', $stockId)
            ->whereDate('purchase_date', $dt->toDateString())
            ->whereRaw('ABS(shares - ?) < 0.0000001', [$shares])
            ->exists();
    }

    /** พอร์ตที่กำลังเลือก (ของ user ปัจจุบัน) */
    private function currentPortfolio(): Portfolio
    {
        $user = auth()->user();
        $id   = session('active_portfolio_id');
        if ($id && ($p = $user->portfolios()->find($id))) {
            return $p;
        }
        return $user->portfolios()->orderBy('id')->first()
            ?? $user->portfolios()->create(['name' => 'พอร์ตของฉัน']);
    }

    private function parseDatetime(?string $s): ?Carbon
    {
        if (!$s) {
            return null;
        }
        try {
            return Carbon::parse($s);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** ถอด JSON (strip ```json fence เหมือน projectFutureAI) */
    private function decodeJson(string $raw)
    {
        $s = trim($raw);
        if (str_starts_with($s, '```json')) {
            $s = substr($s, 7);
        }
        if (str_starts_with($s, '```')) {
            $s = substr($s, 3);
        }
        if (str_ends_with($s, '```')) {
            $s = substr($s, 0, -3);
        }
        return json_decode(trim($s), true);
    }

    private function prompt(): string
    {
        return <<<TXT
คุณคือระบบอ่านรายการซื้อ-ขาย "หุ้น" และ "กองทุนรวม" จากภาพหน้าจอแอป
(เช่น Dime สำหรับหุ้น · K-My Funds, Finnomena สำหรับกองทุน)
อ่านทุกภาพที่แนบมา แล้วดึง "เฉพาะรายการที่สำเร็จ" เท่านั้น

⚠️ ข้ามรายการเหล่านี้ ห้ามนำมาเด็ดขาด:
- ปันผล / รับเงินเข้า / ดอกเบี้ย (Dividend)
- รายการที่ "ไม่สำเร็จ": มีเครื่องหมายตกใจสีแดง (!), "ไม่สำเร็จ", "ล้มเหลว", "รอจับคู่",
  "รอเวลาทำการ", "ยกเลิก", หรือจำนวนหน่วยแสดงเป็น "--"
  (รายการสำเร็จมักมีเครื่องหมายถูกสีเขียว ✓)

ประเภทรายการ:
1. ซื้อ / "ซื้อแบบ DCA"  → type "buy"
2. ขาย                    → type "sell"
3. สับเปลี่ยน (Switch) "A → B" → แตกเป็น 2 รายการ:
   - {type:"sell", symbol:"A", ...(ข้อมูลขาออก), note:"สับเปลี่ยนไป B"}
   - {type:"buy",  symbol:"B", ...(ข้อมูลขาเข้า), note:"สับเปลี่ยนจาก A"}

แต่ละรายการมีฟิลด์:
- type: "buy" หรือ "sell"
- symbol: ชื่อย่อตัวพิมพ์ใหญ่ — หุ้น เช่น NVDA, PTT · กองทุน เช่น K-GHRMF, ONE-UGG-ASSF, K-GOLD-A(D)
- shares: จำนวนหุ้น หรือ "จำนวนหน่วย" ของกองทุน ทศนิยมครบ (เช่น 38.2892, 241.7965)
- price: ราคาต่อหุ้น หรือ "ราคาต่อหน่วย (NAV)" เป็นตัวเลข (เช่น 13.0585)
- amount: มูลค่าที่จ่าย/ได้รับทั้งรายการ (เช่น 500.00, 2750.00)
- currency: ถ้าหน่วยเป็น "บาท"=THB, ถ้า "USD"=USD (กองทุนไทยเป็น THB เสมอ)
- datetime: "YYYY-MM-DD HH:MM:SS" (ถ้าไม่มีเวลาใช้ 00:00:00)
  ⚠️ วันที่ในภาพเป็น พ.ศ. ให้แปลงเป็น ค.ศ. เสมอ (ปี − 543)
     เช่น "22 มิถุนายน 2569" หรือ "18 มิ.ย. 69" = 2026-06-18
- note: ใส่เฉพาะรายการที่มาจากการสับเปลี่ยน (ตามรูปแบบด้านบน) ไม่งั้นเว้นว่าง ""

ตอบเป็น JSON array เท่านั้น ห้ามมีข้อความอื่นหรือ markdown:
[{"type":"buy","symbol":"K-GHRMF","shares":38.2892,"price":13.0585,"amount":500.00,"currency":"THB","datetime":"2026-06-18 00:00:00","note":""},{"type":"sell","symbol":"ONE-UGG-ASSF","shares":381.7249,"price":22.83,"amount":8714.02,"currency":"THB","datetime":"2022-08-22 00:00:00","note":"สับเปลี่ยนไป ONE-TCMSSF-SSF"},{"type":"buy","symbol":"ONE-TCMSSF-SSF","shares":792.7962,"price":10.99,"amount":8714.02,"currency":"THB","datetime":"2022-08-22 00:00:00","note":"สับเปลี่ยนจาก ONE-UGG-ASSF"}]
ถ้าไม่มีรายการเลย ตอบ []
TXT;
    }
}
