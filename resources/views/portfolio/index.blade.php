@extends('layouts.app')

@section('title', 'พอร์ตการลงทุน — Stock AI')

@section('content')

<div class="mb-8">
    <h1 class="text-2xl font-bold text-slate-900">💼 พอร์ตการลงทุน</h1>
    <p class="text-slate-500 text-sm mt-1">กรอกหุ้นที่ถือจริง → ดูมูลค่า กำไร/ขาดทุน และให้ AI ตรวจสุขภาพพอร์ต (แปลง USD→THB ที่ {{ number_format($rate, 2) }} บาท)</p>
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
                    <h3 class="font-semibold text-slate-800">🩺 ตรวจสุขภาพพอร์ตด้วย AI</h3>
                    <button id="healthBtn" class="bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-700 hover:to-purple-700 text-white font-medium px-4 py-2 rounded-xl text-sm transition shadow-sm active:scale-[0.98] disabled:opacity-60">
                        วิเคราะห์พอร์ต
                    </button>
                </div>
                <div id="healthLoading" class="hidden flex-col items-center py-10 text-center">
                    <div class="relative w-12 h-12 mb-3">
                        <div class="absolute inset-0 rounded-full border-4 border-indigo-100"></div>
                        <div class="absolute inset-0 rounded-full border-4 border-indigo-500 border-t-transparent animate-spin"></div>
                    </div>
                    <p class="text-slate-500 text-sm">AI กำลังตรวจสุขภาพพอร์ต...</p>
                </div>
                <div id="healthResult" class="md-content text-sm text-slate-600"></div>
            </div>
        @endif
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
