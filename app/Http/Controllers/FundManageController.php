<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesUserStocks;
use App\Jobs\FetchFundNavJob;
use App\Models\SecFund;
use App\Models\Stock;
use App\Services\SecFundApi;
use Illuminate\Http\Request;

class FundManageController extends Controller
{
    use ScopesUserStocks;

    public function __construct(private SecFundApi $sec) {}

    /**
     * ค้นหากองทุนจาก catalog ในเครื่อง (sec_funds) — SEC ไม่มี search-by-name endpoint
     * catalog sync ผ่าน `php artisan app:sync-fund-catalog`
     */
    public function search(Request $request)
    {
        $q = trim($request->input('q', ''));
        if (strlen($q) < 2) {
            return response()->json([]);
        }

        $rows = SecFund::query()
            ->where('fund_status', 'Registered')
            ->where(function ($w) use ($q) {
                $w->where('proj_abbr_name', 'like', "%{$q}%")
                  ->orWhere('proj_name_th', 'like', "%{$q}%");
            })
            ->orderByRaw('proj_abbr_name like ? desc', ["{$q}%"]) // ขึ้นต้นตรงมาก่อน
            ->limit(10)
            ->get(['proj_id', 'proj_abbr_name', 'proj_name_th']);

        return response()->json($rows);
    }

    /** เพิ่มกองทุนเข้าระบบ + สั่ง backfill NAV ผ่าน queue */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'symbol'  => 'required|string|max:60',
            'proj_id' => 'nullable|string|max:60',
            'years'   => 'required|integer|min:1|max:20',
        ]);

        $symbol = strtoupper(trim($validated['symbol']));
        $user   = $request->user();

        // กองทุนมีใน catalog แล้ว → แค่ attach (ไม่ต้องใช้ key/ดึงซ้ำ)
        $existing = Stock::where('symbol', $symbol)->where('asset_category', 'fund')->first();
        if ($existing) {
            if ($user->stocks()->whereKey($existing->id)->exists()) {
                return $this->respond($request, false, "กองทุน {$symbol} อยู่ในรายการของคุณแล้ว");
            }
            $user->stocks()->attach($existing->id);
            return $this->respond($request, true, "เพิ่มกองทุน {$symbol} แล้ว (ใช้ NAV ที่มีอยู่)");
        }

        // resolve กองทุนจาก catalog (sec_funds) — ใช้ proj_id ที่เลือกมา หรือค้นจากชื่อย่อ
        $fund = $validated['proj_id']
            ? SecFund::where('proj_id', $validated['proj_id'])->first()
            : SecFund::where('proj_abbr_name', $symbol)->first();

        if (!$fund) {
            return $this->respond($request, false, "ไม่พบกองทุน {$symbol} ใน catalog — ถ้ายังไม่เคย sync ให้รัน `php artisan app:sync-fund-catalog` ก่อน แล้วเลือกจากรายการที่ขึ้นมา");
        }

        $projId = $fund->proj_id;
        $symbol = strtoupper($fund->proj_abbr_name);
        $nameTh = $fund->proj_name_th;

        // ต้องมี FundDailyInfo key เพื่อดึง NAV จริง
        if (!$this->sec->hasDailyInfoKey()) {
            return $this->respond($request, false, 'ยังไม่ได้ตั้งค่า FundDailyInfo Key — ไปที่หน้า ตั้งค่า → SEC Thailand API');
        }

        // เลือก class ตัวแทนของกอง (NAV คืนหลาย class ปนกัน) — ทำตอนนี้เพื่อ fail เร็วถ้าไม่มี NAV
        $navClass = $this->sec->pickNavClass($projId);
        if (!$navClass) {
            return $this->respond($request, false, "ยังไม่มีข้อมูล NAV ของกองทุน {$symbol} ใน SEC");
        }

        // สร้าง Stock record (ยังไม่มี NAV — job จะ backfill ให้)
        $stock = Stock::create([
            'symbol'         => $symbol,
            'name'           => $nameTh ?: $symbol,
            'currency'       => 'THB',
            'exchange'       => 'SEC_TH',
            'type'           => 'MUTUALFUND',
            'asset_category' => 'fund',
            'sec_proj_id'    => $projId,
            'sec_nav_class'  => $navClass,
        ]);
        $user->stocks()->syncWithoutDetaching([$stock->id]);

        // สั่งดึง NAV ย้อนหลังแบบ background (ทยอยเข้า ทุกนาทีตาม scheduler)
        FetchFundNavJob::dispatch(
            $stock->id,
            $projId,
            $navClass,
            now()->subYears((int) $validated['years'])->format('Y-m-d'),
        );

        return $this->respond($request, true, "เพิ่มกองทุน {$symbol} แล้ว — กำลังดึง NAV ย้อนหลัง {$validated['years']} ปีในเบื้องหลัง (ทยอยอัปเดต)");
    }

    /** เลิกติดตามกองทุน */
    public function destroy(Stock $stock)
    {
        $this->guardTracksStock($stock);
        $symbol = $stock->symbol;

        auth()->user()->stocks()->detach($stock->id);

        if ($stock->users()->count() === 0) {
            $stock->delete(); // cascade ลบ NAV ใน stock_prices ด้วย
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
