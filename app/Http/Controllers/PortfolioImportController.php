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
use Illuminate\Support\Facades\Log;

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

        // ใช้ model สำหรับ OCR โดยเฉพาะ (Flash) — แยกจาก model /ask
        $gemini = $gemini->useImportModel();
        $raw = $gemini->generateFromImages($this->prompt(), $images);

        // log raw output ไว้ดีบักเวลาเจอ layout โบรกใหม่ที่อ่านพลาด (ดูใน laravel.log)
        Log::info('[portfolio-import] Gemini OCR', [
            'images' => count($images),
            'status' => $gemini->lastStatus,
            'raw'    => $raw,
        ]);
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
            $amount   = (float) ($r['amount'] ?? 0);
            $currency = strtoupper(trim($r['currency'] ?? ''));
            $datetime = $this->parseDatetime($r['datetime'] ?? null);
            $note     = trim((string) ($r['note'] ?? '')); // ที่มา เช่น "สับเปลี่ยนจาก X"

            // ── ทองคำจริง (ออมทอง/ซื้อขายทอง): แปลงน้ำหนัก (กรัม/oz/บาททอง) → บาททอง ──
            // ตรวจจากการ "มี weight (น้ำหนัก)" เป็นหลัก → รองรับ symbol อื่น เช่น MTS-GOLD (Dime)
            // ⚠️ กองทุนทอง (K-GOLD) มี "หน่วย" ไม่มี weight → ไม่เข้าเงื่อนไขนี้ (ยังเป็น fund)
            $weight      = (float) ($r['weight'] ?? 0);
            $isGold      = $weight > 0 || $symbol === 'GOLD' || strtolower((string) ($r['asset'] ?? '')) === 'gold';
            $weightLabel = '';
            if ($isGold) {
                $symbol = 'GOLD';
                $unit   = (string) ($r['weight_unit'] ?? 'g');
                $shares = $this->goldToBahtGold($weight, $unit); // บาททอง
                $price  = ($shares > 0 && $amount > 0) ? $amount / $shares : 0;
                // สกุลของเงินที่จ่าย/ได้รับ อ่านตามจริง (Dime: ซื้อ=บาท ขาย=USD) — ไม่ fix THB
                $currency = in_array($currency, ['THB', 'USD'], true) ? $currency : 'THB';
                $weightLabel = $weight > 0 ? $this->goldWeightLabel($weight, $unit, $shares) : '';
            } else {
                $shares = (float) ($r['shares'] ?? 0);
                $price  = (float) ($r['price'] ?? 0);
            }

            if ($symbol === '' || $shares <= 0) {
                continue; // ข้อมูลไม่ครบ → ข้าม
            }

            $fxRate = isset($r['fx_rate']) && $r['fx_rate'] ? (float) $r['fx_rate'] : null;

            // หาสินทรัพย์ + เพิ่มให้อัตโนมัติถ้ายังไม่ติดตาม (รองรับทั้งหุ้น Yahoo และกองทุน SEC)
            [$stock, $wasNew] = $this->ensureTracked($user, $symbol);
            if (!$stock) {
                $rows[] = $this->row($type, $symbol, null, $shares, $price, $amount, $currency, $fxRate, $datetime, 'invalid', $note, $weightLabel);
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
                $this->isDuplicate($portfolio, $stock->id, $datetime, $shares) ? 'duplicate' : 'new', $note, $weightLabel);
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
            'rows.*.stock_id'   => 'nullable|integer',
            'rows.*.symbol'     => 'nullable|string|max:60',
            'rows.*.shares'     => 'required|numeric|min:0.0000001',
            'rows.*.price'      => 'nullable|numeric|min:0',
            'rows.*.amount'     => 'nullable|numeric|min:0',
            'rows.*.currency'   => 'nullable|in:THB,USD',
            'rows.*.fx_rate'    => 'nullable|numeric|min:1|max:200',
            'rows.*.datetime'   => 'nullable|date',
            'rows.*.note'       => 'nullable|string|max:255',
        ]);

        $portfolio  = $this->currentPortfolio();
        $user       = $request->user();
        $trackedIds = $this->userStocks()->pluck('stocks.id')->all();

        $inserted = 0;
        $skipped  = 0;

        foreach ($data['rows'] as $r) {
            $stock = !empty($r['stock_id']) ? Stock::find($r['stock_id']) : null;

            // stock_id ไม่มี/ไม่ใช่ของ user (เช่น user แก้ symbol ใน preview หรือแถวที่ตอนแรกหาไม่เจอ)
            // → resolve ใหม่จาก symbol ที่พิมพ์ (รองรับทั้งหุ้น Yahoo และกองทุน SEC)
            if ((!$stock || !in_array($stock->id, $trackedIds)) && !empty($r['symbol'])) {
                [$stock] = $this->ensureTracked($user, strtoupper(trim($r['symbol'])));
                if ($stock) {
                    $trackedIds[] = $stock->id;
                }
            }

            // กัน inject: ต้องเป็นสินทรัพย์ที่ user ติดตาม
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

    private function row(string $type, string $symbol, ?int $stockId, float $shares, float $price, float $amount, string $currency, ?float $fxRate, ?Carbon $dt, string $status, string $note = '', string $weightLabel = ''): array
    {
        return [
            'type'         => $type, // buy | sell
            'symbol'       => $symbol,
            'stock_id'     => $stockId,
            'shares'       => $shares,
            'price'        => $price,
            'amount'       => $amount,
            'currency'     => $currency,
            'fx_rate'      => $fxRate,
            'datetime'     => $dt?->format('Y-m-d H:i:s'),
            'note'         => $note, // ที่มา (สับเปลี่ยน) — โชว์ใน preview + เก็บลง DB
            'weight_label' => $weightLabel, // เฉพาะทอง: "0.2125 ก. → 0.0139 บาททอง" (โชว์อย่างเดียว)
            'status'       => $status, // new | duplicate | invalid
        ];
    }

    // ── ทองคำ: แปลงหน่วยน้ำหนัก → บาททอง (หน่วยที่ระบบเก็บใน portfolio_items.shares) ──
    private const GRAMS_PER_BAHT_GOLD = 15.244;  // ทองแท่ง 96.5% 1 บาท = 15.244 กรัม
    private const GRAMS_PER_OZ        = 31.1035; // 1 troy ounce

    private function goldToBahtGold(float $weight, string $unit): float
    {
        if ($weight <= 0) {
            return 0;
        }
        $u = strtolower(trim($unit));
        $grams = match (true) {
            str_contains($u, 'oz'), str_contains($u, 'ออนซ')          => $weight * self::GRAMS_PER_OZ,
            str_contains($u, 'บาท'), $u === 'baht'                     => $weight * self::GRAMS_PER_BAHT_GOLD, // เป็นบาททองอยู่แล้ว
            default                                                    => $weight, // กรัม (ค่าเริ่มต้น)
        };
        return round($grams / self::GRAMS_PER_BAHT_GOLD, 7);
    }

    /** label โชว์ใน preview: น้ำหนักเดิม → บาททอง (ให้ user ตรวจว่าถูก) */
    private function goldWeightLabel(float $weight, string $unit, float $bahtGold): string
    {
        $u = strtolower(trim($unit));
        $unitLabel = (str_contains($u, 'oz') || str_contains($u, 'ออนซ')) ? 'oz'
            : ((str_contains($u, 'บาท') || $u === 'baht') ? 'บาททอง' : 'ก.');
        return rtrim(rtrim(number_format($weight, 4), '0'), '.') . " {$unitLabel} → "
            . rtrim(rtrim(number_format($bahtGold, 7), '0'), '.') . ' บาททอง';
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
คุณคือระบบอ่าน "รายการเคลื่อนไหว/ประวัติการซื้อ-ขาย" ของหุ้นและกองทุนรวม จากภาพหน้าจอแอป
โบรก/แพลตฟอร์มในไทยมีหลายเจ้า (ธนาคาร/บลจ., FundConnext, aggregator, broker หุ้น)
layout และศัพท์ต่างกัน — ให้ยึด "ความหมาย" ของรายการ ไม่ใช่ตำแหน่ง/ชื่อคอลัมน์ที่ตายตัว

════════ อ่านเฉพาะ "หน้ารายการธุรกรรม" เท่านั้น ════════
✅ เอา: หน้าประวัติ/รายการเคลื่อนไหว ที่แต่ละแถวเป็น "ธุรกรรม 1 ครั้ง" (มีวันที่ทำรายการ)
❌ อย่าเอา: หน้า "สรุปพอร์ต/ยอดถือครองปัจจุบัน/มูลค่าตลาด/กำไรขาดทุนรวม"
   (แถวเป็นยอดสะสมของกองทุน ไม่ใช่ธุรกรรม) — ถ้าภาพเป็นแบบนี้ ให้ตอบ []

════════ ประเภทรายการ (ดูจากคำเหล่านี้ ทั้งไทย/อังกฤษ) ════════
- buy  ← "ซื้อ", "ซื้อหน่วยลงทุน", "ซื้อแบบ DCA", "DCA", "ลงทุน", "สมัครซื้อ", "Subscription", "Buy"
- sell ← "ขาย", "ขายคืน", "ไถ่ถอน", "Redemption", "Redeem", "Sell"
- สับเปลี่ยน (Switch): "สับเปลี่ยน", "สับเปลี่ยนเข้า/ออก", "Switch in/out", "โอนย้าย"
  รูปแบบ "A → B" → แตกเป็น 2 รายการ:
    {type:"sell", symbol:"A", ...(ขาออก), note:"สับเปลี่ยนไป B"}
    {type:"buy",  symbol:"B", ...(ขาเข้า), note:"สับเปลี่ยนจาก A"}

════════ ทองคำจริง (ออมทอง / ซื้อขายทองออนไลน์) ════════
ทองคำ "จริง" ที่วัดเป็นน้ำหนัก — แอปเช่น Gold Now (ฮั่วเซ่งเฮง), Dime (MTS-GOLD), ออมทอง, YLG, MTS
สังเกต: รายการมี "น้ำหนักทอง" เป็น กรัม/ออนซ์(oz) — (คนละอย่างกับ "กองทุนทอง" เช่น K-GOLD ที่นับเป็น "หน่วย")
- symbol: ใส่ "GOLD" เสมอ — แม้แอปจะแสดงชื่ออื่น เช่น "MTS-GOLD", "GOLD Wallet", "ทองคำ 96.5%" ก็ตอบ "GOLD"
- type: "สั่งซื้อ"/"ซื้อ"/"ออมทอง"/"ซื้อทอง" = buy · "ขาย"/"ขายคืน"/"ขายทอง" = sell
- weight: ตัวเลข "น้ำหนัก" ของรายการ (เช่น 0.2125, 0.0070) — คำพ้อง: "น้ำหนักทอง", "น้ำหนัก", "Weight" (สำคัญมาก ต้องอ่านให้ได้)
- weight_unit: "g" ถ้า กรัม/gram · "oz" ถ้า ออนซ์/oz/ounce · "baht" ถ้า บาท/บาททอง
- amount: มูลค่าเงินของรายการ ("ราคารวม"/"จำนวนเงิน"/ตัวเลขเงินก้อนใหญ่ที่มุมขวา)
- currency: อ่าน "ตามจริง" ของ amount → "บาท"/THB = "THB" · "USD"/"\$" = "USD"
  (บางแอปเช่น Dime: รายการ "ซื้อ" เป็นบาท แต่ "ขาย" เป็น USD — ให้ดูหน่วยเงินของแต่ละรายการเอง)
- shares, price: ใส่ null (ระบบคำนวณจาก weight + amount เอง — คุณไม่ต้องแปลงหน่วย)
- ข้ามรายการทองที่ "รอทำรายการ/รอราคา/ไม่สำเร็จ" (เอาเฉพาะ "เสร็จสมบูรณ์/สำเร็จ")

════════ ข้ามเด็ดขาด ════════
- ปันผล: "เงินปันผล", "ปันผล", "Dividend", "ดอกเบี้ย", "รับเงินเข้า"
- รายการไม่สำเร็จ: เครื่องหมายตกใจสีแดง (!), "ไม่สำเร็จ", "ล้มเหลว", "ยกเลิก", "Cancelled",
  "Rejected", "รอจับคู่", "รอเวลาทำการ", "รออนุมัติ", "รอดำเนินการ", "Pending", "Expired",
  หรือจำนวนหน่วยแสดงเป็น "--" / ว่าง
  (รายการสำเร็จมักมีถูกสีเขียว ✓ / "สำเร็จ" / "เสร็จสิ้น")

════════ ฟิลด์แต่ละรายการ ════════
- type: "buy" หรือ "sell"
- symbol: ชื่อย่อ/รหัสกองทุน ตัวพิมพ์ใหญ่ (เลือก "รหัสภาษาอังกฤษ" เสมอ ไม่ใช่ชื่อไทยยาวๆ)
    หุ้น เช่น NVDA, PTT · กองทุน เช่น K-GHRMF, ONE-UGG-ASSF, K-GOLD-A(D) · ทองคำ = "GOLD" เสมอ
- shares: จำนวนหน่วย/จำนวนหุ้น (คำพ้อง: "จำนวนหน่วย", "หน่วยลงทุน", "Units", "จำนวนหุ้น") · ทองใช้ null
- weight, weight_unit: เฉพาะทองคำ (ดูหัวข้อ "ทองคำ") — สินทรัพย์อื่นไม่ต้องใส่
- price: ราคา/หน่วย หรือ NAV (คำพ้อง: "ราคาต่อหน่วย", "NAV", "มูลค่าหน่วยลงทุน", "ราคาที่ทำรายการ", "Price")
- amount: มูลค่าทั้งรายการ (คำพ้อง: "จำนวนเงิน", "มูลค่าซื้อ/ขาย", "ยอดเงิน", "Amount")
- currency: หน่วย "บาท"/THB = "THB", "USD"/"\$" = "USD" (กองทุนไทยเป็น THB เสมอ)
- datetime: "YYYY-MM-DD HH:MM:SS" (ไม่มีเวลาใช้ 00:00:00)
- note: ใส่เฉพาะรายการสับเปลี่ยน ไม่งั้นเว้นว่าง ""

════════ วันที่ (สำคัญ) ════════
- รองรับ พ.ศ. เต็ม/ย่อ และ ค.ศ.: "22 มิถุนายน 2569", "18 มิ.ย. 69", "18/06/69", "18-06-2569",
  เดือนไทย (ม.ค.–ธ.ค.) และอังกฤษ (Jan–Dec)
- กฎแปลง: ถ้าปี > 2500 (หรือปี 2 หลักจากแอปไทย) = พ.ศ. → ลบ 543
  เช่น 2569→2026, "69"→2569→2026 · ถ้าเป็น ค.ศ. อยู่แล้ว (2024/2025/2026) คงไว้

════════ กฎกันอ่านมั่ว ════════
- ถ้าอ่านตัวเลข (หน่วย/ราคา/มูลค่า) ไม่ชัด หรือไม่มี → "ข้ามรายการนั้น" อย่าเดาตัวเลขเอง
- ตัวเลขห้ามมี comma หรือหน่วยกำกับ (เช่น 2750.00 ไม่ใช่ "2,750.00 บาท")
- ใช้ null ถ้าไม่มีค่า ห้ามใส่ "-" หรือ "N/A"

ตอบเป็น JSON array ล้วน ห้ามมี markdown/คำอธิบาย/comma เกิน:
[{"type":"buy","symbol":"K-GHRMF","shares":38.2892,"price":13.0585,"amount":500.00,"currency":"THB","datetime":"2026-06-18 00:00:00","note":""},{"type":"buy","symbol":"GOLD","weight":0.2125,"weight_unit":"g","shares":null,"price":null,"amount":1000.00,"currency":"THB","datetime":"2026-03-19 19:15:34","note":""},{"type":"sell","symbol":"GOLD","weight":0.0070,"weight_unit":"oz","shares":null,"price":null,"amount":28.96,"currency":"USD","datetime":"2026-06-19 13:08:39","note":""}]
ถ้าไม่มีรายการธุรกรรมเลย (หรือเป็นหน้าสรุปพอร์ต) ตอบ []
TXT;
    }
}
