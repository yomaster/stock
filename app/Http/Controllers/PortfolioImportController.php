<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesUserStocks;
use App\Models\Portfolio;
use App\Models\PortfolioItem;
use App\Models\Stock;
use App\Services\GeminiService;
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
            if ($symbol === '' || $shares <= 0) {
                continue; // ข้อมูลไม่ครบ → ข้าม
            }

            $fxRate = isset($r['fx_rate']) && $r['fx_rate'] ? (float) $r['fx_rate'] : null;

            // หาหุ้น + เพิ่มให้อัตโนมัติถ้ายังไม่ติดตาม
            [$stock, $wasNew] = $this->ensureTracked($user, $symbol);
            if (!$stock) {
                $rows[] = $this->row($type, $symbol, null, $shares, $price, $amount, $currency, $fxRate, $datetime, 'invalid');
                continue;
            }
            if ($wasNew) {
                $newStocks[$stock->symbol] = true;
            }
            if (!in_array($currency, ['THB', 'USD'], true)) {
                $currency = $stock->currency; // เผื่ออ่านสกุลไม่ได้ → ใช้สกุลของหุ้น
            }

            // เช็คซ้ำ: หุ้น + วันที่ + จำนวนหุ้น (จับได้ทุกกรณี รวม item เดิมที่ไม่มีเวลา)
            $rows[] = $this->row($type, $stock->symbol, $stock->id, $shares, $price, $amount, $currency, $fxRate, $datetime,
                $this->isDuplicate($portfolio, $stock->id, $datetime, $shares) ? 'duplicate' : 'new');
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

    private function row(string $type, string $symbol, ?int $stockId, float $shares, float $price, float $amount, string $currency, ?float $fxRate, ?Carbon $dt, string $status): array
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
คุณคือระบบอ่านรายการซื้อ-ขายหุ้นจากภาพหน้าจอแอปโบรกเกอร์ (เช่น Dime)
อ่านทุกภาพที่แนบมา แล้วดึง "เฉพาะรายการที่สำเร็จ (เสร็จสิ้น)" ทั้ง ซื้อ และ ขาย

⚠️ ข้ามรายการเหล่านี้ ห้ามนำมาเด็ดขาด:
- ปันผล / รับเงินเข้า / ดอกเบี้ย (Dividend)
- รายการที่ยังไม่สำเร็จ: "รอเวลาทำการ", "รอจับคู่", "รอยกเลิก", "ยกเลิก", "ไม่สำเร็จ", "ล้มเหลว"

แต่ละรายการมีฟิลด์:
- type: "buy" ถ้าเป็น "ซื้อ", "sell" ถ้าเป็น "ขาย"
- symbol: ชื่อย่อหุ้น ตัวพิมพ์ใหญ่ (เช่น NVDA, GOOG, PTT)
- shares: จำนวนหุ้นตามจริง ทศนิยมครบ (เช่น 0.2443011)
- price: "ราคาที่ได้จริง" ต่อหุ้น เป็นตัวเลข (เช่น 200.04)
- amount: มูลค่าที่จ่าย/ได้รับทั้งรายการ (เช่น 48.95 หรือ 499.72)
- currency: สกุลเงิน — ถ้าหน่วยเป็น "บาท"=THB, ถ้า "USD"=USD
- datetime: วันเวลา รูปแบบ "YYYY-MM-DD HH:MM:SS"
  ⚠️ วันที่ในภาพเป็น พ.ศ. (เช่น "25 มิ.ย. 69" = 25 มิถุนายน 2569) ให้แปลงเป็น ค.ศ. เสมอ (2569 − 543 = 2026)

ตอบเป็น JSON array เท่านั้น ห้ามมีข้อความอื่นหรือ markdown:
[{"type":"buy","symbol":"NVDA","shares":0.2443011,"price":200.04,"amount":48.95,"currency":"USD","datetime":"2026-06-25 14:27:58"},{"type":"sell","symbol":"AAPL","shares":0.1927012,"price":270.39,"amount":52.11,"currency":"USD","datetime":"2026-04-20 11:40:08"}]
ถ้าไม่มีรายการเลย ตอบ []
TXT;
    }
}
