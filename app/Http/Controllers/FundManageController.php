<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesUserStocks;
use App\Models\Stock;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

class FundManageController extends Controller
{
    use ScopesUserStocks;

    public function __construct(private SettingsService $settings) {}

    /** ค้นหากองทุนจาก SEC API (AJAX autocomplete) */
    public function search(Request $request)
    {
        $q = trim($request->input('q', ''));
        if (strlen($q) < 2) {
            return response()->json([]);
        }

        $key = $this->settings->get('sec.api_key');
        if (!$key) {
            return response()->json([]);
        }

        try {
            $resp = Http::timeout(10)
                ->withHeaders([
                    'accept'                    => 'application/json',
                    'Ocp-Apim-Subscription-Key' => $key,
                ])
                ->get('https://api.sec.or.th/FundFactsheet/fund/autocomplete', [
                    'keyword' => $q,
                ]);

            $results = $resp->successful() ? ($resp->json() ?? []) : [];
            // คืน [{fund_id, name_th, fund_abbr_name}, ...]
            return response()->json(array_slice($results, 0, 10));
        } catch (\Throwable) {
            return response()->json([]);
        }
    }

    /** เพิ่มกองทุนเข้าระบบ + ดึง NAV ย้อนหลัง */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'symbol' => 'required|string|max:30',
            'years'  => 'required|integer|min:1|max:20',
        ]);

        $symbol = strtoupper(trim($validated['symbol']));
        $user   = $request->user();

        // กองทุนมีใน catalog แล้ว → แค่ attach ไม่ต้องใช้ SEC key
        $existing = Stock::where('symbol', $symbol)->where('asset_category', 'fund')->first();
        if ($existing) {
            if ($user->stocks()->whereKey($existing->id)->exists()) {
                return $this->respond($request, false, "กองทุน {$symbol} อยู่ในรายการของคุณแล้ว");
            }
            $user->stocks()->attach($existing->id);
            return $this->respond($request, true, "เพิ่มกองทุน {$symbol} แล้ว (ใช้ NAV ที่มีอยู่)");
        }

        // ยังไม่มีใน catalog → ต้องดึง NAV จาก SEC (ต้องมี key)
        if (!$this->settings->get('sec.api_key')) {
            return $this->respond($request, false, 'ยังไม่ได้ตั้งค่า SEC Thailand Subscription Key — ไปที่หน้า ตั้งค่า → SEC Thailand API (กองทุนรวม)');
        }

        $exitCode = Artisan::call('app:fetch-fund-data', [
            'symbol'  => $symbol,
            '--years' => $validated['years'],
        ]);

        $stock = Stock::where('symbol', $symbol)->first();
        if (!$stock || $exitCode !== 0) {
            return $this->respond($request, false, "ไม่พบกองทุน {$symbol} ใน SEC Thailand — ตรวจสอบรหัสให้ถูกต้อง เช่น K-GHRMF, KFLTF-A");
        }

        $user->stocks()->syncWithoutDetaching([$stock->id]);
        return $this->respond($request, true, "เพิ่มกองทุน {$symbol} สำเร็จ พร้อม NAV ย้อนหลัง {$validated['years']} ปี");
    }

    /** เลิกติดตามกองทุน */
    public function destroy(Stock $stock)
    {
        $this->guardTracksStock($stock);
        $symbol = $stock->symbol;

        auth()->user()->stocks()->detach($stock->id);

        if ($stock->users()->count() === 0) {
            $stock->delete();
            return back()->with('success', "ลบกองทุน {$symbol} ออกจากระบบแล้ว");
        }

        return back()->with('success', "เลิกติดตามกองทุน {$symbol} แล้ว");
    }

    private function respond(Request $request, bool $ok, string $message)
    {
        if ($request->wantsJson()) {
            return response()->json(['success' => $ok, 'message' => $message], $ok ? 200 : 422);
        }
        return back()->with($ok ? 'success' : 'error', $message);
    }
}
