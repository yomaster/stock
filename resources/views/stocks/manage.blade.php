@extends('layouts.app')

@section('title', 'จัดการสินทรัพย์ — Invest AI')

@section('content')

<div class="mb-8">
    <h1 class="text-2xl font-bold text-slate-900">จัดการสินทรัพย์</h1>
    <p class="text-slate-500 text-sm mt-1">เพิ่มหุ้น (Yahoo Finance) หรือกองทุนรวมไทย (SEC Thailand) เข้าระบบ</p>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    {{-- Left column: Add Stock + Add Fund forms --}}
    <div class="space-y-6">

        {{-- Add Stock Form --}}
        <div class="glass-card p-6">
            <h2 class="font-semibold text-slate-800 mb-4 flex items-center gap-2">
                <span class="w-6 h-6 bg-indigo-100 text-indigo-600 rounded-md flex items-center justify-center text-xs font-bold">+</span>
                เพิ่มหุ้น / ETF
            </h2>

            <form id="addStockForm" data-action="{{ route('manage.store') }}" class="space-y-4">
                @csrf
                <div>
                    <label class="block text-sm font-medium text-slate-600 mb-1.5">Symbol หุ้น</label>
                    <input type="text" name="symbol" placeholder="เช่น AAPL, PTT.BK, NVDA"
                        class="w-full border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm text-slate-800 placeholder-slate-400
                               focus:outline-none focus:ring-2 focus:ring-indigo-400 focus:border-transparent transition bg-white/70">
                    <p class="text-xs text-slate-400 mt-1.5">หุ้นไทยใช้ suffix .BK เช่น <strong>PTT.BK</strong> · US ใช้ symbol ตรงๆ เช่น <strong>AAPL</strong></p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-600 mb-1.5">ดึงข้อมูลย้อนหลัง</label>
                    <select name="years" class="w-full border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm text-slate-800
                                                focus:outline-none focus:ring-2 focus:ring-indigo-400 focus:border-transparent bg-white/70">
                        @foreach([1 => '1 ปี', 3 => '3 ปี', 5 => '5 ปี (แนะนำ)', 10 => '10 ปี', 15 => '15 ปี', 20 => '20 ปี'] as $y => $label)
                            <option value="{{ $y }}" {{ $y == 5 ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <button type="submit" id="addBtn"
                    class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2.5 rounded-xl text-sm
                           transition-all shadow-sm hover:shadow-indigo-200 hover:shadow-lg active:scale-[0.98]
                           disabled:opacity-60 disabled:cursor-not-allowed flex items-center justify-center gap-2">
                    <span id="addBtnText">ดึงข้อมูลและเพิ่ม</span>
                </button>
            </form>

            <div class="mt-5 pt-5 border-t border-slate-100">
                <p class="text-xs font-medium text-slate-500 mb-3">หุ้นยอดนิยม</p>
                <div class="flex flex-wrap gap-2">
                    @foreach(['AAPL','NVDA','MSFT','TSLA','GOOGL','PTT.BK','ADVANC.BK','CPALL.BK','SCB.BK','AOT.BK'] as $s)
                    <button type="button" data-symbol="{{ $s }}"
                        class="quick-symbol px-2.5 py-1 bg-slate-100 hover:bg-indigo-50 hover:text-indigo-600 text-slate-600 rounded-lg text-xs font-medium transition cursor-pointer border border-transparent hover:border-indigo-200">
                        {{ $s }}
                    </button>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Add Fund Form (SEC Thailand) --}}
        <div class="glass-card p-6">
            <h2 class="font-semibold text-slate-800 mb-4 flex items-center gap-2">
                <span class="w-6 h-6 bg-emerald-100 text-emerald-600 rounded-md flex items-center justify-center text-xs font-bold">+</span>
                เพิ่มกองทุนรวมไทย
            </h2>

            <form id="addFundForm" data-action="{{ route('funds.store') }}" class="space-y-4">
                @csrf
                <div class="relative">
                    <label class="block text-sm font-medium text-slate-600 mb-1.5">รหัสกองทุน</label>
                    <input type="text" id="fundSymbolInput" name="symbol"
                        placeholder="เช่น K-GHRMF, KFLTF-A, TMBGQG"
                        autocomplete="off"
                        class="w-full border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm text-slate-800 placeholder-slate-400
                               focus:outline-none focus:ring-2 focus:ring-emerald-400 focus:border-transparent transition bg-white/70">
                    {{-- proj_id ของกองทุนที่เลือกจากรายการ (กัน search ซ้ำตอน store) --}}
                    <input type="hidden" id="fundProjId" name="proj_id" value="">
                    {{-- Autocomplete dropdown --}}
                    <ul id="fundSuggestions"
                        class="hidden absolute z-50 mt-1 w-full bg-white rounded-xl shadow-lg border border-slate-100 max-h-56 overflow-y-auto text-sm">
                    </ul>
                    <p class="text-xs text-slate-400 mt-1.5">ค้นหาชื่อหรือรหัสกองทุนจาก SEC Thailand — พิมพ์อย่างน้อย 2 ตัวอักษร</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-600 mb-1.5">ดึง NAV ย้อนหลัง</label>
                    <select name="years" class="w-full border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm text-slate-800
                                                focus:outline-none focus:ring-2 focus:ring-emerald-400 focus:border-transparent bg-white/70">
                        @foreach([1 => '1 ปี', 3 => '3 ปี', 5 => '5 ปี (แนะนำ)', 10 => '10 ปี'] as $y => $label)
                            <option value="{{ $y }}" {{ $y == 5 ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <button type="submit" id="addFundBtn"
                    class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-medium py-2.5 rounded-xl text-sm
                           transition-all shadow-sm hover:shadow-emerald-200 hover:shadow-lg active:scale-[0.98]
                           disabled:opacity-60 disabled:cursor-not-allowed flex items-center justify-center gap-2">
                    <span id="addFundBtnText">ดึง NAV และเพิ่มกองทุน</span>
                </button>
            </form>
        </div>

        {{-- Track Gold (singleton — ราคา GTA) --}}
        <div class="glass-card p-6">
            <h2 class="font-semibold text-slate-800 mb-2 flex items-center gap-2">
                <span class="w-6 h-6 bg-amber-100 text-amber-600 rounded-md flex items-center justify-center text-xs font-bold">+</span>
                เพิ่มทองคำ
            </h2>
            <p class="text-xs text-slate-400 mb-4">ทองคำแท่ง 96.5% · ราคาอ้างอิงสมาคมค้าทองคำ (GTA) · หน่วยเป็นบาททอง</p>
            <form method="POST" action="{{ route('manage.gold') }}">
                @csrf
                <button type="submit"
                    class="w-full bg-amber-500 hover:bg-amber-600 text-white font-medium py-2.5 rounded-xl text-sm transition shadow-sm active:scale-[0.98]">
                    🥇 ติดตามทองคำแท่ง 96.5%
                </button>
            </form>
        </div>

    </div>{{-- /Left column --}}

    {{-- Asset List (Stocks + Funds) --}}
    <div class="lg:col-span-2 glass-card p-6">
        <h2 class="font-semibold text-slate-800 mb-4">สินทรัพย์ในระบบ ({{ $stocks->count() }} รายการ)</h2>

        @if($stocks->isEmpty())
            <div class="text-center py-16">
                <div class="text-5xl mb-3">📭</div>
                <p class="text-slate-400 text-sm">ยังไม่มีสินทรัพย์ — เพิ่มจากแบบฟอร์มด้านซ้าย</p>
            </div>
        @else
            @php
                // เรียงลำดับ section ตามชนิดสินทรัพย์ (controller เรียง asset_category มาแล้ว)
                $sections = ['stock' => '📈 หุ้น', 'etf' => '📦 ETF', 'fund' => '🏦 กองทุนรวม', 'gold' => '🥇 ทองคำ'];
                $grouped  = $stocks->groupBy('asset_category');
            @endphp
            <div class="space-y-6">
            @foreach($sections as $cat => $secLabel)
                @php $items = $grouped->get($cat); @endphp
                @continue(!$items || $items->isEmpty())
                <div>
                    <h3 class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-2">
                        {{ $secLabel }} <span class="text-slate-400 font-normal">· {{ $items->count() }} รายการ</span>
                    </h3>
                    <div class="space-y-2">
                    @foreach($items as $stock)
                    @php
                        $isStock = in_array($stock->asset_category, ['stock', 'etf']);
                        $isFund  = $stock->asset_category === 'fund';
                        $badge   = match($stock->asset_category) {
                            'etf'   => ['label' => 'ETF',    'cls' => 'bg-blue-100 text-blue-600'],
                            'fund'  => ['label' => 'กองทุน', 'cls' => 'bg-emerald-100 text-emerald-600'],
                            'gold'  => ['label' => 'ทองคำ',  'cls' => 'bg-yellow-100 text-yellow-600'],
                            default => ['label' => 'หุ้น',   'cls' => 'bg-indigo-100 text-indigo-600'],
                        };
                    @endphp
                <div class="flex items-center justify-between p-3.5 bg-white/60 border border-slate-100 rounded-xl hover:border-indigo-200 hover:bg-indigo-50/30 transition group">
                    <div class="flex items-center gap-3">
                        <div class="w-9 h-9 bg-gradient-to-br from-slate-100 to-slate-200 rounded-lg flex items-center justify-center">
                            <span class="text-xs font-bold text-slate-500">{{ substr($stock->symbol, 0, 2) }}</span>
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <a href="{{ route('assets.show', $stock) }}" class="font-semibold text-slate-800 hover:text-indigo-600 transition text-sm">{{ $stock->symbol }}</a>
                                <span class="text-xs px-1.5 py-0.5 rounded-md font-medium {{ $badge['cls'] }}">{{ $badge['label'] }}</span>
                            </div>
                            <div class="text-xs text-slate-400">{{ $stock->name ?? '—' }} · {{ $stock->exchange ?? '—' }}</div>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        @if($isStock)
                        {{-- Refresh — หุ้น/ETF เท่านั้น (Yahoo Finance) --}}
                        <form method="POST" action="{{ route('manage.refresh', $stock) }}" class="inline">
                            @csrf
                            <input type="hidden" name="years" value="1">
                            <button type="submit" title="อัปเดตราคาล่าสุด"
                                class="p-2 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                                </svg>
                            </button>
                        </form>
                        @endif

                        {{-- Analyze --}}
                        <a href="{{ route('assets.analyze', $stock) }}"
                           class="p-2 text-slate-400 hover:text-purple-600 hover:bg-purple-50 rounded-lg transition" title="AI วิเคราะห์">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z"/>
                            </svg>
                        </a>

                        {{-- Delete — route ต่างกันตาม type --}}
                        @if($isFund)
                        <form method="POST" action="{{ route('funds.destroy', $stock) }}" class="inline confirm-delete"
                              data-title="ลบกองทุน {{ $stock->symbol }}?"
                              data-message="ข้อมูล NAV ย้อนหลังและผลวิเคราะห์ของ {{ $stock->symbol }} จะถูกลบด้วย">
                            @csrf
                            @method('DELETE')
                            <button type="submit" title="ลบ"
                                class="p-2 text-slate-300 hover:text-red-500 hover:bg-red-50 rounded-lg transition">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                </svg>
                            </button>
                        </form>
                        @else
                        <form method="POST" action="{{ route('manage.destroy', $stock) }}" class="inline confirm-delete"
                              data-title="ลบ {{ $stock->symbol }}?"
                              data-message="ข้อมูลราคาและผลวิเคราะห์ทั้งหมดของ {{ $stock->symbol }} จะถูกลบด้วย">
                            @csrf
                            @method('DELETE')
                            <button type="submit" title="ลบ"
                                class="p-2 text-slate-300 hover:text-red-500 hover:bg-red-50 rounded-lg transition">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                </svg>
                            </button>
                        </form>
                        @endif
                    </div>
                </div>
                    @endforeach
                    </div>{{-- /space-y-2 --}}
                </div>{{-- /section --}}
            @endforeach
            </div>{{-- /space-y-6 --}}
        @endif
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    // ── Stock form ──────────────────────────────────────────────────────────
    document.querySelectorAll('.quick-symbol').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelector('[name=symbol]').value = btn.dataset.symbol;
        });
    });

    const stockForm    = document.getElementById('addStockForm');
    const addBtn       = document.getElementById('addBtn');
    const addBtnText   = document.getElementById('addBtnText');

    stockForm.addEventListener('submit', async function (e) {
        e.preventDefault();

        const symbol = stockForm.querySelector('[name=symbol]').value.trim();
        if (!symbol) { window.toast('error', 'กรุณากรอก Symbol หุ้น'); return; }

        addBtn.disabled = true;
        addBtnText.innerHTML = '<span class="inline-block w-4 h-4 border-2 border-white/40 border-t-white rounded-full animate-spin"></span> กำลังดึงข้อมูลจาก Yahoo Finance...';

        try {
            const res  = await fetch(stockForm.dataset.action, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' },
                body: new FormData(stockForm),
            });
            const data = await res.json();
            window.toast(data.success ? 'success' : 'error', data.message);
            if (data.success) {
                stockForm.querySelector('[name=symbol]').value = '';
                setTimeout(() => window.location.reload(), 1400);
            }
        } catch (err) {
            window.toast('error', 'เกิดข้อผิดพลาด: ' + err.message);
        } finally {
            addBtn.disabled = false;
            addBtnText.textContent = 'ดึงข้อมูลและเพิ่ม';
        }
    });

    // ── Fund form + SEC autocomplete ────────────────────────────────────────
    const fundForm       = document.getElementById('addFundForm');
    const fundInput      = document.getElementById('fundSymbolInput');
    const fundProjId     = document.getElementById('fundProjId');
    const fundSuggestions = document.getElementById('fundSuggestions');
    const fundBtn        = document.getElementById('addFundBtn');
    const fundBtnText    = document.getElementById('addFundBtnText');
    const searchUrl      = '{{ route('funds.search') }}';

    let debounceTimer = null;

    fundInput.addEventListener('input', function () {
        clearTimeout(debounceTimer);
        fundProjId.value = '';   // พิมพ์เอง = ยกเลิก proj_id ที่เคยเลือก (กันส่งค่าค้าง)
        const q = this.value.trim();
        if (q.length < 2) { hideSuggestions(); return; }

        debounceTimer = setTimeout(async () => {
            try {
                const res  = await fetch(`${searchUrl}?q=${encodeURIComponent(q)}`, {
                    headers: { 'Accept': 'application/json' }
                });
                const data = await res.json();
                renderSuggestions(data);
            } catch { hideSuggestions(); }
        }, 300);
    });

    function renderSuggestions(items) {
        fundSuggestions.innerHTML = '';
        if (!items.length) { hideSuggestions(); return; }

        items.forEach(item => {
            const abbr = item.proj_abbr_name ?? '';
            const li = document.createElement('li');
            li.className = 'px-3.5 py-2.5 hover:bg-emerald-50 cursor-pointer border-b border-slate-50 last:border-0';
            li.innerHTML = `<span class="font-semibold text-slate-800">${abbr}</span>
                            <span class="text-slate-400 ml-2 text-xs">${item.proj_name_th ?? ''}</span>`;
            li.addEventListener('click', () => {
                fundInput.value  = abbr;
                fundProjId.value = item.proj_id ?? '';   // จำ proj_id ไว้ส่งตอน submit
                hideSuggestions();
            });
            fundSuggestions.appendChild(li);
        });
        fundSuggestions.classList.remove('hidden');
    }

    function hideSuggestions() {
        fundSuggestions.classList.add('hidden');
        fundSuggestions.innerHTML = '';
    }

    // ปิด dropdown เมื่อคลิกนอก
    document.addEventListener('click', (e) => {
        if (!fundInput.contains(e.target) && !fundSuggestions.contains(e.target)) {
            hideSuggestions();
        }
    });

    fundForm.addEventListener('submit', async function (e) {
        e.preventDefault();

        const symbol = fundInput.value.trim();
        if (!symbol) { window.toast('error', 'กรุณากรอกรหัสกองทุน'); return; }

        hideSuggestions();
        fundBtn.disabled = true;
        // ดึง NAV จาก SEC อาจใช้เวลา 10-30 วินาที
        fundBtnText.innerHTML = '<span class="inline-block w-4 h-4 border-2 border-white/40 border-t-white rounded-full animate-spin"></span> กำลังดึง NAV จาก SEC Thailand...';

        try {
            const res  = await fetch(fundForm.dataset.action, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' },
                body: new FormData(fundForm),
            });
            const data = await res.json();
            window.toast(data.success ? 'success' : 'error', data.message);
            if (data.success) {
                fundInput.value = '';
                setTimeout(() => window.location.reload(), 1400);
            }
        } catch (err) {
            window.toast('error', 'เกิดข้อผิดพลาด: ' + err.message);
        } finally {
            fundBtn.disabled = false;
            fundBtnText.textContent = 'ดึง NAV และเพิ่มกองทุน';
        }
    });
});
</script>
@endpush
