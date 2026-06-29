@extends('layouts.app')

@section('title', 'AI วิเคราะห์ — ' . $stock->symbol)

@section('content')

<div class="mb-6">
    <a href="{{ route('assets.show', $stock) }}" class="text-slate-400 hover:text-slate-600 text-xs font-medium">← {{ $stock->symbol }}</a>
    <h1 class="text-2xl font-bold text-slate-900 mt-2">AI วิเคราะห์ — {{ $stock->symbol }}</h1>
    <p class="text-slate-500 text-sm mt-0.5">คาดการณ์ผลตอบแทน Bull / Base / Bear ด้วย Gemini AI</p>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    {{-- Form --}}
    <div class="glass-card p-6 self-start">
        <h2 class="font-semibold text-slate-800 mb-5">ตั้งค่าการวิเคราะห์</h2>
        <form id="analyzeForm" data-action="{{ route('assets.analyze.run', $stock) }}" class="space-y-4">
            @csrf

            {{-- Currency selector --}}
            <div>
                <label class="block text-sm font-medium text-slate-600 mb-1.5">สกุลเงินที่ใช้ลงทุน</label>
                <div class="grid grid-cols-2 gap-2">
                    @foreach(['THB' => '🇹🇭 บาท (THB)', 'USD' => '🇺🇸 ดอลลาร์ (USD)'] as $code => $label)
                    <label class="relative cursor-pointer">
                        <input type="radio" name="display_currency" value="{{ $code }}"
                               {{ ($stock->currency === 'USD' ? 'THB' : $stock->currency) === $code ? 'checked' : '' }}
                               class="peer sr-only" onchange="toggleExRate()">
                        <div class="border border-slate-200 peer-checked:border-indigo-500 peer-checked:bg-indigo-50 rounded-xl p-3 text-center text-sm font-medium text-slate-600 peer-checked:text-indigo-700 transition">
                            {{ $label }}
                        </div>
                    </label>
                    @endforeach
                </div>
            </div>

            {{-- Exchange rate --}}
            <div id="exRateBox" class="{{ $stock->currency === 'USD' ? '' : 'hidden' }}">
                <label class="block text-sm font-medium text-slate-600 mb-1.5">อัตราแลกเปลี่ยน (1 USD = ? THB)</label>
                <input type="number" name="exchange_rate" step="any" min="1" max="200" value="33"
                       class="w-full border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400 focus:border-transparent bg-white/70">
                <p class="text-xs text-slate-400 mt-1">Dime จะคิดตามอัตราจริงวันที่ซื้อ</p>
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-600 mb-1.5">เงินลงทุนครั้งแรก</label>
                <div class="relative">
                    <input type="number" name="initial_amount" value="0" min="0" step="any" inputmode="decimal"
                        class="w-full border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400 focus:border-transparent bg-white/70 pr-16">
                    <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-slate-400 currency-label">THB</span>
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-600 mb-1.5">DCA ต่อเดือน</label>
                <div class="relative">
                    <input type="number" name="monthly_amount" value="5000" min="0" step="any" inputmode="decimal"
                        class="w-full border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400 focus:border-transparent bg-white/70 pr-16">
                    <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-slate-400 currency-label">THB</span>
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-600 mb-1.5">ระยะเวลา</label>
                <div class="grid grid-cols-3 gap-1.5">
                    @foreach([5,10,15,20,25,30] as $y)
                    <label class="cursor-pointer">
                        <input type="radio" name="years" value="{{ $y }}" {{ $y == 10 ? 'checked' : '' }} class="peer sr-only">
                        <div class="border border-slate-200 peer-checked:border-indigo-500 peer-checked:bg-indigo-50 rounded-lg py-2 text-center text-xs font-medium text-slate-600 peer-checked:text-indigo-700 transition hover:border-slate-300">
                            {{ $y }} ปี
                        </div>
                    </label>
                    @endforeach
                </div>
            </div>

            <button type="submit" id="analyzeBtn"
                class="w-full bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-700 hover:to-purple-700 text-white font-semibold py-2.5 rounded-xl text-sm transition-all shadow-sm hover:shadow-lg hover:shadow-indigo-200 active:scale-[0.98] disabled:opacity-60 disabled:cursor-not-allowed">
                🤖 วิเคราะห์ด้วย AI
            </button>
        </form>
    </div>

    {{-- Result area --}}
    <div class="lg:col-span-2">
        {{-- Loading state (ซ่อนไว้ก่อน) --}}
        <div id="analyzeLoading" class="hidden glass-card flex-col items-center justify-center py-20 text-center">
            <div class="relative w-16 h-16 mb-5">
                <div class="absolute inset-0 rounded-full border-4 border-indigo-100"></div>
                <div class="absolute inset-0 rounded-full border-4 border-indigo-500 border-t-transparent animate-spin"></div>
                <div class="absolute inset-0 flex items-center justify-center text-2xl">🤖</div>
            </div>
            <p class="text-slate-700 font-medium">AI กำลังวิเคราะห์...</p>
            <p id="loadingHint" class="text-slate-400 text-sm mt-1">กำลังดึงข่าวและงบการเงินมาประมวลผล</p>
        </div>

        {{-- Result (AJAX จะเติม HTML ตรงนี้) --}}
        <div id="analyzeResult">
            @if(!empty($latest))
                {{-- ผลวิเคราะห์ล่าสุดที่เก็บไว้ — แสดงเลยโดยไม่เรียก AI ใหม่ (ประหยัด token) --}}
                <div class="mb-3 flex items-center gap-2 text-xs">
                    <span class="bg-indigo-50 text-indigo-600 px-2.5 py-1 rounded-full font-medium">📌 ผลวิเคราะห์ล่าสุด: {{ $latest->created_at->format('d/m/Y H:i') }}</span>
                    <span class="text-slate-400">กดปุ่ม "วิเคราะห์ด้วย AI" เพื่อวิเคราะห์ใหม่</span>
                </div>
                @include('stocks._analyze_result', ['result' => $latest->result])
            @else
                <div class="glass-card flex flex-col items-center justify-center py-20 text-center">
                    <div class="w-16 h-16 bg-gradient-to-br from-indigo-100 to-purple-100 rounded-2xl flex items-center justify-center mb-4 text-3xl">🤖</div>
                    <p class="text-slate-500 font-medium">กรอกข้อมูลแล้วกด "วิเคราะห์ด้วย AI"</p>
                    <p class="text-slate-400 text-sm mt-1">ระบบจะใช้ Gemini AI วิเคราะห์แนวโน้มและคาดการณ์ผลตอบแทน</p>
                </div>
            @endif
        </div>
    </div>
</div>

@endsection

@push('scripts')
<script>
function toggleExRate() {
    const currency = document.querySelector('[name=display_currency]:checked')?.value;
    const stockCurrency = '{{ $stock->currency }}';
    const box = document.getElementById('exRateBox');
    document.querySelectorAll('.currency-label').forEach(l => l.textContent = currency);
    // แสดงช่องอัตราแลกเปลี่ยนเฉพาะตอนแปลงข้ามสกุล (THB กับหุ้น USD)
    if (currency === 'THB' && stockCurrency === 'USD') {
        box.classList.remove('hidden');
    } else {
        box.classList.add('hidden');
    }
}

let projChart = null;
function renderProjectionChart(d) {
    const el = document.getElementById('projectionChart');
    if (!el || !d) return;
    if (projChart) projChart.destroy();
    projChart = new Chart(el, {
        type: 'line',
        data: {
            labels: d.labels,
            datasets: [
                { label: 'เงินลงทุนสะสม', data: d.invested, borderColor: '#cbd5e1', borderDash: [5,3], borderWidth: 1.5, pointRadius: 0, fill: false },
                { label: '🚀 Bull', data: d.bull, borderColor: '#10b981', borderWidth: 2, pointRadius: 3, pointBackgroundColor: '#10b981', fill: false },
                { label: '📊 Base', data: d.base, borderColor: '#6366f1', borderWidth: 2.5, pointRadius: 3, pointBackgroundColor: '#6366f1', fill: false },
                { label: '🐻 Bear', data: d.bear, borderColor: '#f43f5e', borderWidth: 2, pointRadius: 3, pointBackgroundColor: '#f43f5e', fill: false },
            ]
        },
        options: {
            responsive: true,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'top', labels: { font: { size: 11 }, usePointStyle: true, pointStyleWidth: 10 } },
                tooltip: {
                    callbacks: {
                        label: ctx => ` ${ctx.dataset.label}: ${ctx.parsed.y.toLocaleString('th-TH')} ${d.currency}`
                    }
                }
            },
            scales: {
                x: { ticks: { font: { size: 10 }, color: '#94a3b8' }, grid: { color: '#f1f5f9' } },
                y: {
                    ticks: {
                        font: { size: 10 }, color: '#94a3b8',
                        callback: v => v >= 1e6 ? (v/1e6).toFixed(1)+'M' : v >= 1e3 ? (v/1e3).toFixed(0)+'K' : v
                    },
                    grid: { color: '#f1f5f9' }
                }
            }
        }
    });
}

document.addEventListener('DOMContentLoaded', function () {
    toggleExRate();

    // วาดกราฟของผลวิเคราะห์ล่าสุดที่เก็บไว้ (ถ้ามี)
    @if(!empty($latest) && !empty($latest->chart))
    renderProjectionChart(@json($latest->chart));
    @endif

    const form    = document.getElementById('analyzeForm');
    const btn     = document.getElementById('analyzeBtn');
    const loading = document.getElementById('analyzeLoading');
    const result  = document.getElementById('analyzeResult');
    const hint    = document.getElementById('loadingHint');

    const hints = [
        'กำลังดึงข่าวและงบการเงินมาประมวลผล',
        'กำลังประเมินสถานการณ์ Bull / Base / Bear',
        'กำลังคำนวณผลตอบแทนทบต้น...',
        'ใกล้เสร็จแล้ว กำลังสรุปผล...'
    ];

    form.addEventListener('submit', async function (e) {
        e.preventDefault();

        // เข้าสู่สถานะ loading
        btn.disabled = true;
        btn.textContent = '⏳ กำลังวิเคราะห์...';
        result.classList.add('hidden');
        loading.classList.remove('hidden');
        loading.classList.add('flex');

        // หมุนข้อความ hint ระหว่างรอ
        let hi = 0;
        const hintTimer = setInterval(() => {
            hi = (hi + 1) % hints.length;
            hint.textContent = hints[hi];
        }, 4000);

        try {
            const res = await fetch(form.dataset.action, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    'Accept': 'application/json',
                },
                body: new FormData(form),
            });

            if (!res.ok) throw new Error('HTTP ' + res.status);
            const data = await res.json();

            result.innerHTML = data.html;
            if (data.success && data.chart) {
                renderProjectionChart(data.chart);
            }
        } catch (err) {
            result.innerHTML = '<div class="glass-card p-6 border border-red-200 bg-red-50"><p class="text-red-600 text-sm">เกิดข้อผิดพลาด: ' + err.message + ' — กรุณาลองใหม่อีกครั้ง</p></div>';
        } finally {
            clearInterval(hintTimer);
            loading.classList.add('hidden');
            loading.classList.remove('flex');
            result.classList.remove('hidden');
            btn.disabled = false;
            btn.textContent = '🤖 วิเคราะห์ด้วย AI';
        }
    });
});
</script>
@endpush
