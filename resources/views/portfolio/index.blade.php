@extends('layouts.app')

@section('title', 'พอร์ตการลงทุน — Stock AI')

@section('content')

<div class="flex flex-wrap items-start justify-between gap-4 mb-8">
    <div>
        <h1 class="text-2xl font-bold text-slate-900">💼 พอร์ตการลงทุน</h1>
        <p class="text-slate-500 text-sm mt-1">ราคาหุ้นสดจาก Yahoo (~15 นาที) · เรท USD→THB ≈ {{ number_format($rate, 2) }} บาท · กำไร/ขาดทุนคิดทั้งราคาหุ้นและค่าเงิน</p>
    </div>

    {{-- ตัวเลือกพอร์ต --}}
    <div class="flex items-center gap-2">
        <select id="portfolioSwitch" onchange="location.href=this.value"
            class="border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm font-medium text-slate-700 bg-white/70 focus:outline-none focus:ring-2 focus:ring-indigo-400 max-w-48">
            @foreach($portfolios as $p)
                <option value="{{ route('portfolio.portfolios.switch', $p) }}" {{ $p->id === $portfolio->id ? 'selected' : '' }}>
                    {{ $p->name }}
                </option>
            @endforeach
        </select>

        <button type="button" id="renamePortfolioBtn" title="แก้ไขชื่อพอร์ต" data-name="{{ $portfolio->name }}"
            class="p-2.5 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 border border-slate-200 rounded-xl transition">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
        </button>

        <button type="button" id="newPortfolioBtn" title="สร้างพอร์ตใหม่"
            class="p-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl transition shadow-sm">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
        </button>

        @if($portfolios->count() > 1)
        <form method="POST" action="{{ route('portfolio.portfolios.destroy', $portfolio) }}" class="confirm-delete"
              data-title="ลบพอร์ต {{ $portfolio->name }}?" data-message="หุ้นทั้งหมดในพอร์ตนี้จะถูกลบด้วย">
            @csrf @method('DELETE')
            <button type="submit" title="ลบพอร์ตนี้"
                class="p-2.5 text-slate-400 hover:text-red-500 hover:bg-red-50 border border-slate-200 rounded-xl transition">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
            </button>
        </form>
        @endif

        {{-- ฟอร์มซ่อนสำหรับสร้างพอร์ต (เรียกผ่าน SweetAlert) --}}
        <form id="newPortfolioForm" method="POST" action="{{ route('portfolio.portfolios.store') }}" class="hidden">
            @csrf
            <input type="hidden" name="name" id="newPortfolioName">
        </form>
        {{-- ฟอร์มซ่อนสำหรับเปลี่ยนชื่อพอร์ต --}}
        <form id="renamePortfolioForm" method="POST" action="{{ route('portfolio.portfolios.rename', $portfolio) }}" class="hidden">
            @csrf @method('PUT')
            <input type="hidden" name="name" id="renamePortfolioName">
        </form>
    </div>
</div>

{{-- สรุปรวม --}}
@if(!empty($holdings))
@php $isProfit = $total_pl_thb >= 0; @endphp
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <div class="glass-card p-4 text-center">
        <div class="text-xs text-slate-500 mb-1">มูลค่าพอร์ต</div>
        <div class="text-xl font-bold text-slate-800">{{ number_format($total_value_thb, 0) }}</div>
        <div class="text-xs text-slate-400">บาท</div>
    </div>
    <div class="glass-card p-4 text-center">
        <div class="text-xs text-slate-500 mb-1">เงินลงทุน (ทุน)</div>
        <div class="text-xl font-bold text-slate-800">{{ number_format($total_cost_thb, 0) }}</div>
        <div class="text-xs text-slate-400">บาท</div>
    </div>
    <div class="glass-card p-4 text-center {{ $isProfit ? 'bg-emerald-50' : 'bg-red-50' }}">
        <div class="text-xs {{ $isProfit ? 'text-emerald-600' : 'text-red-600' }} mb-1">กำไร/ขาดทุน</div>
        <div class="text-xl font-bold {{ $isProfit ? 'text-emerald-700' : 'text-red-700' }}">{{ $isProfit ? '+' : '' }}{{ number_format($total_pl_thb, 0) }}</div>
        <div class="text-xs {{ $isProfit ? 'text-emerald-500' : 'text-red-500' }}">บาท</div>
    </div>
    <div class="glass-card p-4 text-center {{ $isProfit ? 'bg-emerald-50' : 'bg-red-50' }}">
        <div class="text-xs {{ $isProfit ? 'text-emerald-600' : 'text-red-600' }} mb-1">ผลตอบแทน</div>
        <div class="text-2xl font-bold {{ $isProfit ? 'text-emerald-700' : 'text-red-700' }}">{{ $isProfit ? '+' : '' }}{{ number_format($total_pl_percent, 1) }}%</div>
    </div>
</div>
@endif

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    {{-- ฟอร์มเพิ่มหุ้น --}}
    <div class="glass-card p-6 self-start">
        <h2 class="font-semibold text-slate-800 mb-4">เพิ่มหุ้นเข้าพอร์ต</h2>
        <form method="POST" action="{{ route('portfolio.items.store') }}" class="space-y-4" id="addItemForm">
            @csrf
            <div>
                <label class="block text-sm font-medium text-slate-600 mb-1.5">หุ้น</label>
                <select name="stock_id" required class="w-full border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm bg-white/70 focus:outline-none focus:ring-2 focus:ring-indigo-400">
                    <option value="">— เลือกหุ้น —</option>
                    @foreach($stocks as $s)
                        <option value="{{ $s->id }}">{{ $s->symbol }} — {{ $s->name }}</option>
                    @endforeach
                </select>
                @error('stock_id') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            {{-- เลือกวิธีเพิ่ม --}}
            <div>
                <label class="block text-sm font-medium text-slate-600 mb-1.5">วิธีเพิ่ม</label>
                <div class="grid grid-cols-2 gap-2">
                    <label class="cursor-pointer">
                        <input type="radio" name="mode" value="amount" checked class="peer sr-only" onchange="switchMode('amount')">
                        <div class="border border-slate-200 peer-checked:border-indigo-500 peer-checked:bg-indigo-50 rounded-xl py-2.5 text-center text-xs font-medium text-slate-600 peer-checked:text-indigo-700 transition">
                            💵 ตามจำนวนเงิน
                        </div>
                    </label>
                    <label class="cursor-pointer">
                        <input type="radio" name="mode" value="shares" class="peer sr-only" onchange="switchMode('shares')">
                        <div class="border border-slate-200 peer-checked:border-indigo-500 peer-checked:bg-indigo-50 rounded-xl py-2.5 text-center text-xs font-medium text-slate-600 peer-checked:text-indigo-700 transition">
                            📊 ตามจำนวนหุ้น
                        </div>
                    </label>
                </div>
            </div>

            {{-- โหมดจำนวนเงิน --}}
            <div id="mode-amount" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-slate-600 mb-1.5">จำนวนเงินที่ลงทุน</label>
                    <input type="number" name="invested_amount" step="any" min="1" placeholder="เช่น 5000"
                        class="w-full border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm bg-white/70 focus:outline-none focus:ring-2 focus:ring-indigo-400">
                    @error('invested_amount') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-600 mb-1.5">สกุลเงินที่จ่าย</label>
                    <div class="grid grid-cols-2 gap-2">
                        @foreach(['THB' => '🇹🇭 บาท', 'USD' => '🇺🇸 ดอลลาร์'] as $code => $label)
                        <label class="cursor-pointer">
                            <input type="radio" name="invested_currency" value="{{ $code }}" {{ $code === 'THB' ? 'checked' : '' }} class="peer sr-only">
                            <div class="border border-slate-200 peer-checked:border-indigo-500 peer-checked:bg-indigo-50 rounded-xl py-2.5 text-center text-sm font-medium text-slate-600 peer-checked:text-indigo-700 transition">
                                {{ $label }}
                            </div>
                        </label>
                        @endforeach
                    </div>
                    <p class="text-xs text-slate-400 mt-1">Dime ซื้อหุ้น US ด้วยเงินบาท → เลือกบาท</p>
                </div>
            </div>

            {{-- โหมดจำนวนหุ้น (รวบรัด) --}}
            <div id="mode-shares" class="space-y-4 hidden">
                <div>
                    <label class="block text-sm font-medium text-slate-600 mb-1.5">จำนวนหุ้นที่ถืออยู่</label>
                    <input type="number" name="shares" step="any" min="0.0001" placeholder="เช่น 0.5 (เศษหุ้นได้)" disabled
                        class="w-full border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm bg-white/70 focus:outline-none focus:ring-2 focus:ring-indigo-400">
                    @error('shares') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-600 mb-1.5">ต้นทุนเฉลี่ย/หุ้น <span class="text-slate-400 font-normal">(ไม่บังคับ)</span></label>
                    <input type="number" name="avg_cost" step="any" min="0" placeholder="เว้นว่าง = ใช้ราคาปัจจุบันเป็นฐาน" disabled
                        class="w-full border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm bg-white/70 focus:outline-none focus:ring-2 focus:ring-indigo-400">
                    <p class="text-xs text-slate-400 mt-1">สกุลของหุ้น (ไทย=บาท, US=ดอลลาร์) · ถ้าไม่รู้ปล่อยว่างได้</p>
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-600 mb-1.5">วันที่ <span class="text-slate-400 font-normal">(ไม่บังคับ — default วันนี้)</span></label>
                <input type="date" name="purchase_date" max="{{ now()->toDateString() }}"
                    class="w-full border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm bg-white/70 focus:outline-none focus:ring-2 focus:ring-indigo-400">
                <p class="text-xs text-slate-400 mt-1">ระบบดึงราคา + อัตราแลกเปลี่ยนวันนั้นมาคำนวณให้</p>
                @error('purchase_date') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2.5 rounded-xl text-sm transition shadow-sm active:scale-[0.98]">
                + เพิ่มเข้าพอร์ต
            </button>
        </form>
    </div>

    {{-- รายการถือครอง + chart + AI --}}
    <div class="lg:col-span-2 space-y-6">
        @if(empty($holdings))
            <div class="glass-card flex flex-col items-center justify-center py-20 text-center">
                <div class="w-16 h-16 bg-gradient-to-br from-indigo-100 to-purple-100 rounded-2xl flex items-center justify-center mb-4 text-3xl">💼</div>
                <p class="text-slate-500 font-medium">พอร์ตยังว่าง</p>
                <p class="text-slate-400 text-sm mt-1">เพิ่มหุ้นที่ถือจริงจากฟอร์มด้านซ้าย</p>
            </div>
        @else
            {{-- Allocation donut + table --}}
            <div class="glass-card p-6">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 items-center">
                    <div>
                        <h3 class="font-semibold text-slate-800 mb-3">สัดส่วนพอร์ต</h3>
                        <canvas id="allocChart" height="200"></canvas>
                    </div>
                    <div class="space-y-2">
                        @foreach($allocation as $i => $a)
                        <div class="flex items-center justify-between text-sm">
                            <div class="flex items-center gap-2">
                                <span class="w-3 h-3 rounded-full" style="background: var(--c{{ $i }})"></span>
                                <span class="font-medium text-slate-700">{{ $a['symbol'] }}</span>
                            </div>
                            <span class="text-slate-500">{{ number_format($a['allocation'], 1) }}%</span>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- ตารางถือครอง (AJAX pagination) --}}
            <div class="glass-card p-6" id="holdingsContainer">
                @include('portfolio._holdings')
            </div>

            {{-- AI Health Check --}}
            <div class="glass-card p-6">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h3 class="font-semibold text-slate-800">🩺 ตรวจสุขภาพพอร์ตด้วย AI</h3>
                        <p id="healthAt" class="text-xs text-slate-400 mt-0.5 {{ $latestHealthAt ? '' : 'hidden' }}">
                            วิเคราะห์ล่าสุด: <span id="healthAtValue">{{ $latestHealthAt }}</span>
                        </p>
                    </div>
                    <button id="healthBtn" class="bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-700 hover:to-purple-700 text-white font-medium px-4 py-2 rounded-xl text-sm transition shadow-sm active:scale-[0.98] disabled:opacity-60">
                        {{ $latestHealthHtml ? 'วิเคราะห์ใหม่' : 'วิเคราะห์พอร์ต' }}
                    </button>
                </div>
                <div id="healthLoading" class="hidden flex-col items-center py-10 text-center">
                    <div class="relative w-12 h-12 mb-3">
                        <div class="absolute inset-0 rounded-full border-4 border-indigo-100"></div>
                        <div class="absolute inset-0 rounded-full border-4 border-indigo-500 border-t-transparent animate-spin"></div>
                    </div>
                    <p class="text-slate-500 text-sm">AI กำลังตรวจสุขภาพพอร์ต...</p>
                </div>
                {{-- แสดงผลวิเคราะห์ล่าสุด (ถ้ามี) --}}
                <div id="healthResult" class="md-content text-sm text-slate-600">{!! $latestHealthHtml !!}</div>
            </div>
        @endif
    </div>
</div>

{{-- Modal แก้ไขรายการถือครอง --}}
<div id="editModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40 backdrop-blur-sm p-4">
    <div class="glass-card !bg-white w-full max-w-md p-6 max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between mb-5">
            <h3 class="font-bold text-slate-800">แก้ไข <span id="editSymbol" class="text-indigo-600"></span></h3>
            <button type="button" id="editClose" class="w-8 h-8 flex items-center justify-center text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded-lg transition">✕</button>
        </div>

        <form id="editForm" method="POST" class="space-y-4">
            @csrf
            @method('PUT')
            <input type="hidden" name="mode" id="editMode" value="amount">

            {{-- โหมด --}}
            <div class="grid grid-cols-2 gap-2">
                <button type="button" data-mode="amount" class="edit-mode-btn border border-slate-200 rounded-xl py-2.5 text-xs font-medium text-slate-600 transition">💵 ตามจำนวนเงิน</button>
                <button type="button" data-mode="shares" class="edit-mode-btn border border-slate-200 rounded-xl py-2.5 text-xs font-medium text-slate-600 transition">📊 ตามจำนวนหุ้น</button>
            </div>

            {{-- โหมดจำนวนเงิน --}}
            <div id="editAmountGroup" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-slate-600 mb-1.5">จำนวนเงินที่ลงทุน</label>
                    <input type="number" name="invested_amount" id="editInvested" step="any" min="1"
                        class="w-full border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-400">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-600 mb-1.5">สกุลเงินที่จ่าย</label>
                    <div class="grid grid-cols-2 gap-2">
                        @foreach(['THB' => '🇹🇭 บาท', 'USD' => '🇺🇸 ดอลลาร์'] as $code => $label)
                        <label class="cursor-pointer">
                            <input type="radio" name="invested_currency" value="{{ $code }}" class="peer sr-only edit-cur">
                            <div class="border border-slate-200 peer-checked:border-indigo-500 peer-checked:bg-indigo-50 rounded-xl py-2.5 text-center text-sm font-medium text-slate-600 peer-checked:text-indigo-700 transition">{{ $label }}</div>
                        </label>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- โหมดจำนวนหุ้น --}}
            <div id="editSharesGroup" class="space-y-4 hidden">
                <div>
                    <label class="block text-sm font-medium text-slate-600 mb-1.5">จำนวนหุ้นที่ถืออยู่</label>
                    <input type="number" name="shares" id="editShares" step="any" min="0.0000001" disabled
                        class="w-full border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-400">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-600 mb-1.5">ต้นทุนเฉลี่ย/หุ้น <span class="text-slate-400 font-normal">(ไม่บังคับ)</span></label>
                    <input type="number" name="avg_cost" id="editAvgCost" step="any" min="0" disabled
                        class="w-full border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-400">
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-600 mb-1.5">วันที่</label>
                <input type="date" name="purchase_date" id="editDate" max="{{ now()->toDateString() }}"
                    class="w-full border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-400">
            </div>

            <div class="flex gap-2 pt-2">
                <button type="button" id="editCancel" class="flex-1 border border-slate-200 text-slate-600 font-medium py-2.5 rounded-xl text-sm hover:bg-slate-50 transition">ยกเลิก</button>
                <button type="submit" class="flex-1 bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2.5 rounded-xl text-sm transition shadow-sm">บันทึก</button>
            </div>
        </form>
    </div>
</div>

@endsection

@push('scripts')
{{-- สลับโหมดเพิ่มหุ้น (เงิน / จำนวนหุ้น) — แสดงตลอดแม้พอร์ตว่าง --}}
<script>
function switchMode(mode) {
    const amt = document.getElementById('mode-amount');
    const shr = document.getElementById('mode-shares');
    const amtInputs = amt.querySelectorAll('input');
    const shrInputs = shr.querySelectorAll('input');

    if (mode === 'shares') {
        shr.classList.remove('hidden'); amt.classList.add('hidden');
        shrInputs.forEach(i => i.disabled = false);
        amtInputs.forEach(i => i.disabled = true);
        document.querySelector('[name=shares]').required = true;
        document.querySelector('[name=invested_amount]').required = false;
    } else {
        amt.classList.remove('hidden'); shr.classList.add('hidden');
        amtInputs.forEach(i => i.disabled = false);
        shrInputs.forEach(i => i.disabled = true);
        document.querySelector('[name=invested_amount]').required = true;
        document.querySelector('[name=shares]').required = false;
    }
}
document.addEventListener('DOMContentLoaded', () => switchMode('amount'));

// ── สร้างพอร์ตใหม่ (Swal prompt) ──
document.getElementById('newPortfolioBtn')?.addEventListener('click', async function () {
    const { value } = await Swal.fire({
        title: 'สร้างพอร์ตใหม่',
        input: 'text',
        inputLabel: 'ชื่อพอร์ต',
        inputPlaceholder: 'เช่น พอร์ตเกษียณ, พอร์ตหุ้นซิ่ง',
        showCancelButton: true,
        confirmButtonText: 'สร้าง',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#4f46e5',
        customClass: { popup: 'rounded-2xl' },
        inputValidator: v => (!v || !v.trim()) ? 'กรุณาใส่ชื่อพอร์ต' : null,
    });
    if (value && value.trim()) {
        document.getElementById('newPortfolioName').value = value.trim();
        document.getElementById('newPortfolioForm').submit();
    }
});

// ── เปลี่ยนชื่อพอร์ต (Swal prompt prefill ชื่อเดิม) ──
document.getElementById('renamePortfolioBtn')?.addEventListener('click', async function () {
    const { value } = await Swal.fire({
        title: 'แก้ไขชื่อพอร์ต',
        input: 'text',
        inputLabel: 'ชื่อพอร์ต',
        inputValue: this.dataset.name || '',
        showCancelButton: true,
        confirmButtonText: 'บันทึก',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#4f46e5',
        customClass: { popup: 'rounded-2xl' },
        inputValidator: v => (!v || !v.trim()) ? 'กรุณาใส่ชื่อพอร์ต' : null,
    });
    if (value && value.trim()) {
        document.getElementById('renamePortfolioName').value = value.trim();
        document.getElementById('renamePortfolioForm').submit();
    }
});

// ── Edit modal ──
function editSwitchMode(mode) {
    document.getElementById('editMode').value = mode;
    const aG = document.getElementById('editAmountGroup');
    const sG = document.getElementById('editSharesGroup');
    document.querySelectorAll('.edit-mode-btn').forEach(b => {
        const on = b.dataset.mode === mode;
        b.classList.toggle('border-indigo-500', on);
        b.classList.toggle('bg-indigo-50', on);
        b.classList.toggle('text-indigo-700', on);
    });
    if (mode === 'shares') {
        sG.classList.remove('hidden'); aG.classList.add('hidden');
        sG.querySelectorAll('input').forEach(i => i.disabled = false);
        aG.querySelectorAll('input').forEach(i => i.disabled = true);
        document.getElementById('editShares').required = true;
        document.getElementById('editInvested').required = false;
    } else {
        aG.classList.remove('hidden'); sG.classList.add('hidden');
        aG.querySelectorAll('input').forEach(i => i.disabled = false);
        sG.querySelectorAll('input').forEach(i => i.disabled = true);
        document.getElementById('editInvested').required = true;
        document.getElementById('editShares').required = false;
    }
}

function openEditModal(btn) {
    const d = btn.dataset;
    document.getElementById('editForm').action = `{{ url('portfolio/items') }}/${d.id}`;
    document.getElementById('editSymbol').textContent = d.symbol;
    document.getElementById('editInvested').value = d.invested || '';
    document.getElementById('editShares').value = d.shares || '';
    document.getElementById('editAvgCost').value = (d.avgCost && parseFloat(d.avgCost) > 0) ? d.avgCost : '';
    document.querySelectorAll('.edit-cur').forEach(r => r.checked = (r.value === (d.currency || 'THB')));

    const dateEl = document.getElementById('editDate');
    if (dateEl._flatpickr) dateEl._flatpickr.setDate(d.date || null, false);
    else dateEl.value = d.date || '';

    editSwitchMode(d.mode || 'amount');

    const m = document.getElementById('editModal');
    m.classList.remove('hidden'); m.classList.add('flex');
}
function closeEditModal() {
    const m = document.getElementById('editModal');
    m.classList.add('hidden'); m.classList.remove('flex');
}

document.addEventListener('click', function (e) {
    const editBtn = e.target.closest('.edit-item-btn');
    if (editBtn) { openEditModal(editBtn); return; }
    const modeBtn = e.target.closest('.edit-mode-btn');
    if (modeBtn) { e.preventDefault(); editSwitchMode(modeBtn.dataset.mode); return; }
});
document.getElementById('editClose')?.addEventListener('click', closeEditModal);
document.getElementById('editCancel')?.addEventListener('click', closeEditModal);
document.getElementById('editModal')?.addEventListener('click', e => { if (e.target.id === 'editModal') closeEditModal(); });
</script>
@endpush

@push('scripts')
@if(!empty($holdings))
<script>
document.addEventListener('DOMContentLoaded', function () {
    const palette = ['#6366f1','#10b981','#f43f5e','#f59e0b','#06b6d4','#a855f7','#ec4899','#84cc16'];
    // ตั้ง CSS var ให้ legend สีตรงกับ chart
    palette.forEach((c, i) => document.documentElement.style.setProperty('--c' + i, c));

    const labels = @json(array_map(fn($a) => $a['symbol'], $allocation));
    const values = @json(array_map(fn($a) => round($a['value_thb'], 2), $allocation));

    new Chart(document.getElementById('allocChart'), {
        type: 'doughnut',
        data: {
            labels,
            datasets: [{ data: values, backgroundColor: palette, borderWidth: 2, borderColor: '#fff' }]
        },
        options: {
            responsive: true,
            cutout: '62%',
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => ` ${ctx.label}: ${ctx.parsed.toLocaleString('th-TH')} บาท`
                    }
                }
            }
        }
    });

    // AI Health Check
    const btn = document.getElementById('healthBtn');
    const loading = document.getElementById('healthLoading');
    const result = document.getElementById('healthResult');

    btn.addEventListener('click', async function () {
        btn.disabled = true;
        result.textContent = '';
        loading.classList.remove('hidden');
        loading.classList.add('flex');
        try {
            const res = await fetch('{{ route('portfolio.health') }}', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    'Accept': 'application/json',
                },
            });
            const data = await res.json();
            if (data.success) {
                result.innerHTML = data.analysis_html;
                // อัปเดตเวลาวิเคราะห์ล่าสุด + ปุ่มเป็น "วิเคราะห์ใหม่"
                if (data.analyzed_at) {
                    document.getElementById('healthAtValue').textContent = data.analyzed_at;
                    document.getElementById('healthAt').classList.remove('hidden');
                }
                btn.textContent = 'วิเคราะห์ใหม่';
            } else {
                window.toast('error', data.message || 'วิเคราะห์ไม่สำเร็จ');
            }
        } catch (e) {
            window.toast('error', 'เกิดข้อผิดพลาด: ' + e.message);
        } finally {
            loading.classList.add('hidden');
            loading.classList.remove('flex');
            btn.disabled = false;
        }
    });

    // AJAX pagination รายการถือครอง (event delegation เพราะเนื้อหาถูก swap)
    const container = document.getElementById('holdingsContainer');
    container.addEventListener('click', async function (e) {
        const btn = e.target.closest('.pg-btn');
        if (!btn) return;
        const page = btn.dataset.page;
        container.style.opacity = '0.5';
        try {
            const res = await fetch(`{{ route('portfolio.holdings') }}?page=${page}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            container.innerHTML = await res.text();
        } catch (e) {
            window.toast('error', 'โหลดหน้าไม่สำเร็จ');
        } finally {
            container.style.opacity = '1';
        }
    });
});
</script>
@endif
@endpush
