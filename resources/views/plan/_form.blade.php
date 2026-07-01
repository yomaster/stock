@php
    // $plan = Plan (แก้ไข) หรือ null (สร้างใหม่)
    // $formPortfolio = พอร์ตที่กำลังดึงสินทรัพย์มาแสดง · $formAssets = รายการสินทรัพย์ · $savedDca = ยอดที่บันทึกไว้
    $val = fn(string $k, $default = null) => old($k, $plan?->{$k} ?? $default);
    $freq = old('frequency', $plan?->frequency ?? 'monthly');
    $daysRaw = old('frequency_days_raw', $plan && $plan->frequency_days ? implode(', ', $plan->frequency_days) : '');
    $startDate = old('start_date', $plan?->start_date?->toDateString() ?? now()->toDateString());
    $planId = $plan?->id;
@endphp

<div class="grid grid-cols-1 md:grid-cols-2 gap-x-5 gap-y-4">

    {{-- ชื่อแผน --}}
    <div class="md:col-span-2">
        <label class="block text-xs font-medium text-slate-600 mb-1.5">ชื่อแผน</label>
        <input type="text" name="name" value="{{ $val('name', 'แผน DCA หลัก') }}" required maxlength="100"
            class="w-full border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400 bg-white/70">
    </div>

    {{-- เลือกพอร์ต (เปลี่ยน = ดึงสินทรัพย์ใหม่) --}}
    <div>
        <label class="block text-xs font-medium text-slate-600 mb-1.5">พอร์ตที่ใช้</label>
        <select name="portfolio_id" required data-plan-id="{{ $planId }}" data-portfolio-reload
            class="w-full border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400 bg-white/70">
            @foreach($portfolios as $pf)
                <option value="{{ $pf->id }}" {{ ($formPortfolio && $formPortfolio->id === $pf->id) ? 'selected' : '' }}>
                    {{ $pf->name }}
                </option>
            @endforeach
        </select>
    </div>

    {{-- วันเริ่ม DCA --}}
    <div>
        <label class="block text-xs font-medium text-slate-600 mb-1.5">เริ่ม DCA วันที่</label>
        <input type="date" name="start_date" value="{{ $startDate }}" required
            class="w-full border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400 bg-white/70">
    </div>

    {{-- ความถี่ --}}
    <div class="md:col-span-2">
        <label class="block text-xs font-medium text-slate-600 mb-1.5">ความถี่ DCA</label>
        <div class="grid grid-cols-3 sm:grid-cols-5 gap-1.5">
            @foreach(['monthly' => 'ทุกเดือน', 'weekly' => 'ทุกสัปดาห์', 'daily' => 'ทุกวัน', 'custom' => 'กำหนดวันเอง', 'once' => 'ครั้งเดียว'] as $fv => $label)
                <label class="cursor-pointer">
                    <input type="radio" name="frequency" value="{{ $fv }}" {{ $freq === $fv ? 'checked' : '' }} class="peer sr-only" data-freq>
                    <div class="border border-slate-200 peer-checked:border-indigo-500 peer-checked:bg-indigo-50 rounded-xl py-2 text-center text-xs font-medium text-slate-600 peer-checked:text-indigo-700 transition hover:border-slate-300">
                        {{ $label }}
                    </div>
                </label>
            @endforeach
        </div>
        {{-- วันที่ของเดือน (เฉพาะ custom) --}}
        <div id="customDaysBox" class="mt-2 {{ $freq === 'custom' ? '' : 'hidden' }}">
            <input type="text" name="frequency_days_raw" value="{{ $daysRaw }}" placeholder="ระบุวันที่ของเดือน เช่น 4, 20"
                class="w-full border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400 bg-white/70">
            <p class="text-[11px] text-slate-400 mt-1">ลงทุนทุกวันที่ระบุของทุกเดือน (คั่นด้วยจุลภาค) — เช่น 4, 20 = เดือนละ 2 ครั้ง</p>
        </div>
    </div>

    {{-- อายุ (optional) --}}
    <div>
        <label class="block text-xs font-medium text-slate-600 mb-1.5">อายุปัจจุบัน <span class="text-slate-300">(ไม่บังคับ)</span></label>
        <input type="number" name="current_age" value="{{ $val('current_age') }}" min="1" max="120" placeholder="เช่น 30"
            class="w-full border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400 bg-white/70">
    </div>
    <div>
        <label class="block text-xs font-medium text-slate-600 mb-1.5">อายุเกษียณ <span class="text-slate-300">(ไม่บังคับ)</span></label>
        <input type="number" name="retire_age" value="{{ $val('retire_age') }}" min="1" max="120" placeholder="เช่น 60"
            class="w-full border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400 bg-white/70">
    </div>

    {{-- จำนวนปี --}}
    <div class="md:col-span-2">
        <label class="block text-xs font-medium text-slate-600 mb-1.5">จำนวนปีที่จะ DCA</label>
        <div class="grid grid-cols-4 sm:grid-cols-7 gap-1.5">
            @foreach([5, 10, 15, 20, 25, 30, 40] as $y)
                <label class="cursor-pointer">
                    <input type="radio" name="years" value="{{ $y }}" {{ (int) $val('years', 10) === $y ? 'checked' : '' }} class="peer sr-only">
                    <div class="border border-slate-200 peer-checked:border-indigo-500 peer-checked:bg-indigo-50 rounded-lg py-2 text-center text-xs font-medium text-slate-600 peer-checked:text-indigo-700 transition hover:border-slate-300">
                        {{ $y }} ปี
                    </div>
                </label>
            @endforeach
        </div>
        <p class="text-[11px] text-slate-400 mt-1">หรือกรอกอายุปัจจุบัน + เกษียณ ระบบจะคำนวณจำนวนปีให้ (ถ้าไม่เลือกด้านบน)</p>
    </div>
</div>

{{-- ─── สินทรัพย์ในพอร์ต: มูลค่าตั้งต้น + ยอด DCA รายตัว ─── --}}
<div class="mt-5 pt-5 border-t border-slate-100">
    <h3 class="font-semibold text-slate-800 text-sm mb-1">สินทรัพย์ในพอร์ต <span class="text-slate-400 font-normal">{{ $formPortfolio->name ?? '' }}</span></h3>
    <p class="text-xs text-slate-400 mb-3">
        มูลค่าตั้งต้นดึงจากพอร์ตอัตโนมัติ · กรอก <strong>ยอด DCA ต่อครั้ง</strong> (เว้นว่าง = ถือเฉยๆ) ·
        <strong>CAGR/ปี</strong> ตั้งต้นให้ที่ {{ (int) round(\App\Services\DcaPlanService::CAGR_MAX * 100) }}% (ผลตอบแทนหุ้นระยะยาวจริง) ปรับเองได้ตามที่คาดหวัง
    </p>

    @if(empty($formAssets))
        <div class="rounded-xl bg-amber-50 border border-amber-200 p-4 text-sm text-amber-700">
            พอร์ตนี้ยังไม่มีสินทรัพย์คงเหลือ — เพิ่มหุ้น/กองทุน/ทองในพอร์ตก่อน แล้วกลับมาสร้างแผน
        </div>
    @else
        @php
            $oldAmounts = old('amounts', []);
            $oldCagr = old('cagr', []);
            $oldExcluded = old('excluded', $savedExcluded ?? []);
        @endphp
        <div class="space-y-2">
            @foreach($formAssets as $a)
                {{-- อ่าน array ตรงๆ — เลี่ยง dot-notation ของ old() ที่เพี้ยนกับ symbol มีจุด (เช่น PTT.BK) --}}
                @php
                    $amt = $oldAmounts[$a['symbol']] ?? $savedDca[$a['symbol']] ?? '';
                    // CAGR prefill: ค่าที่ user เคยปรับ > ค่า cap อัตโนมัติ
                    $cagrVal = $oldCagr[$a['symbol']] ?? ($savedCagr[$a['symbol']] ?? $a['cagr_pct']);
                    $isExcluded = in_array($a['symbol'], $oldExcluded, true);
                @endphp
                <div data-asset-row data-symbol="{{ $a['symbol'] }}"
                     class="flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white/60 p-3 transition {{ $isExcluded ? 'opacity-50' : '' }}">
                    {{-- checkbox ซ่อน = สถานะ "เอาออกจากแผน" (submit เฉพาะตอน checked) --}}
                    <input type="checkbox" name="excluded[]" value="{{ $a['symbol'] }}" class="exclude-cb hidden" {{ $isExcluded ? 'checked' : '' }}>
                    <div class="min-w-0 flex-1">
                        <div class="font-medium text-slate-800 text-sm truncate {{ $isExcluded ? 'line-through' : '' }}">{{ $a['symbol'] }}</div>
                        <div class="text-xs text-slate-400 truncate">{{ $a['name'] }}</div>
                    </div>
                    <div class="text-right shrink-0">
                        <div class="text-xs text-slate-400">มูลค่าตั้งต้น</div>
                        <div class="text-sm font-medium text-slate-700">{{ number_format($a['value_thb'], 0) }} ฿</div>
                    </div>
                    <div class="shrink-0 w-24">
                        <label class="block text-xs text-slate-400 mb-0.5">CAGR/ปี (%)</label>
                        <input type="number" name="cagr[{{ $a['symbol'] }}]" value="{{ $cagrVal }}" step="any" inputmode="decimal" placeholder="12" {{ $isExcluded ? 'disabled' : '' }}
                            class="asset-input w-full border border-slate-200 rounded-lg px-2.5 py-1.5 text-sm text-right focus:outline-none focus:ring-2 focus:ring-indigo-400 bg-white disabled:bg-slate-100">
                        <div class="text-[10px] text-slate-400 mt-0.5 text-right">
                            @if($a['cagr_hist_pct'] !== null)
                                อดีต {{ number_format($a['cagr_hist_pct'], 1) }}%
                            @else
                                ข้อมูลไม่พอ
                            @endif
                        </div>
                    </div>
                    <div class="shrink-0 w-28">
                        <label class="block text-xs text-slate-400 mb-0.5">DCA/ครั้ง (฿)</label>
                        <input type="number" name="amounts[{{ $a['symbol'] }}]" value="{{ $amt }}" min="0" step="any" inputmode="decimal" placeholder="0" {{ $isExcluded ? 'disabled' : '' }}
                            class="asset-input w-full border border-slate-200 rounded-lg px-2.5 py-1.5 text-sm text-right focus:outline-none focus:ring-2 focus:ring-indigo-400 bg-white disabled:bg-slate-100">
                    </div>
                    {{-- ปุ่มลบ/เพิ่มกลับ --}}
                    <button type="button" data-exclude-toggle title="เอาออก/เพิ่มกลับ"
                        class="shrink-0 p-2 rounded-lg border border-slate-200 text-slate-400 hover:text-red-500 hover:bg-red-50 transition">
                        <svg data-icon-remove class="w-4 h-4 {{ $isExcluded ? 'hidden' : '' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                        <svg data-icon-restore class="w-4 h-4 {{ $isExcluded ? '' : 'hidden' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h10a5 5 0 015 5v2m-14-7l4-4m-4 4l4 4"/></svg>
                    </button>
                </div>
            @endforeach
        </div>
    @endif
</div>

@error('name')       <p class="text-xs text-red-500 mt-2">{{ $message }}</p> @enderror
@error('start_date') <p class="text-xs text-red-500 mt-2">{{ $message }}</p> @enderror
