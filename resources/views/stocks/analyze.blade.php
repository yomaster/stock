@extends('layouts.app')

@section('title', 'AI วิเคราะห์ — ' . $stock->symbol)

@section('content')

<div class="mb-6">
    <a href="{{ route('stocks.show', $stock) }}" class="text-slate-400 hover:text-slate-600 text-xs font-medium">← {{ $stock->symbol }}</a>
    <h1 class="text-2xl font-bold text-slate-900 mt-2">AI วิเคราะห์ — {{ $stock->symbol }}</h1>
    <p class="text-slate-500 text-sm mt-0.5">คาดการณ์ผลตอบแทน Bull / Base / Bear ด้วย Gemini AI</p>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    {{-- Form --}}
    <div class="glass-card p-6">
        <h2 class="font-semibold text-slate-800 mb-5">ตั้งค่าการวิเคราะห์</h2>
        <form method="POST" action="{{ route('stocks.analyze.run', $stock) }}" class="space-y-4">
            @csrf

            {{-- Currency selector --}}
            <div>
                <label class="block text-sm font-medium text-slate-600 mb-1.5">สกุลเงินที่ใช้ลงทุน</label>
                <div class="grid grid-cols-2 gap-2">
                    @foreach(['THB' => '🇹🇭 บาท (THB)', 'USD' => '🇺🇸 ดอลลาร์ (USD)'] as $code => $label)
                    <label class="relative cursor-pointer">
                        <input type="radio" name="display_currency" value="{{ $code }}"
                               {{ old('display_currency', $stock->currency === 'USD' ? 'THB' : $stock->currency) === $code ? 'checked' : '' }}
                               class="peer sr-only" onchange="toggleExRate()">
                        <div class="border border-slate-200 peer-checked:border-indigo-500 peer-checked:bg-indigo-50 rounded-xl p-3 text-center text-sm font-medium text-slate-600 peer-checked:text-indigo-700 transition">
                            {{ $label }}
                        </div>
                    </label>
                    @endforeach
                </div>
            </div>

            {{-- Exchange rate (แสดงเมื่อเลือก THB กับหุ้น USD) --}}
            <div id="exRateBox" class="{{ old('display_currency', 'THB') === 'THB' && $stock->currency === 'USD' ? '' : 'hidden' }}">
                <label class="block text-sm font-medium text-slate-600 mb-1.5">อัตราแลกเปลี่ยน (1 USD = ? THB)</label>
                <input type="number" name="exchange_rate" step="0.01" min="1" max="200"
                       value="{{ old('exchange_rate', 36) }}"
                       class="w-full border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400 focus:border-transparent bg-white/70">
                <p class="text-xs text-slate-400 mt-1">ค่าเงินปัจจุบัน ≈ 36 บาท/USD (Dime จะคิดตามอัตราจริงวันที่ซื้อ)</p>
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-600 mb-1.5">เงินลงทุนครั้งแรก</label>
                <div class="relative">
                    <input type="number" name="initial_amount" value="{{ old('initial_amount', 0) }}" min="0" step="1000"
                        class="w-full border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400 focus:border-transparent bg-white/70 pr-16">
                    <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-slate-400 currency-label">THB</span>
                </div>
                @error('initial_amount') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-600 mb-1.5">DCA ต่อเดือน</label>
                <div class="relative">
                    <input type="number" name="monthly_amount" value="{{ old('monthly_amount', 5000) }}" min="0" step="500"
                        class="w-full border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400 focus:border-transparent bg-white/70 pr-16">
                    <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-slate-400 currency-label">THB</span>
                </div>
                @error('monthly_amount') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-600 mb-1.5">ระยะเวลา</label>
                <div class="grid grid-cols-3 gap-1.5">
                    @foreach([5,10,15,20,25,30] as $y)
                    <label class="cursor-pointer">
                        <input type="radio" name="years" value="{{ $y }}" {{ old('years', 10) == $y ? 'checked' : '' }} class="peer sr-only">
                        <div class="border border-slate-200 peer-checked:border-indigo-500 peer-checked:bg-indigo-50 rounded-lg py-2 text-center text-xs font-medium text-slate-600 peer-checked:text-indigo-700 transition hover:border-slate-300">
                            {{ $y }} ปี
                        </div>
                    </label>
                    @endforeach
                </div>
            </div>

            <div class="bg-amber-50 border border-amber-100 rounded-xl p-3 text-xs text-amber-700 flex items-start gap-2">
                <span class="shrink-0 mt-0.5">⚡</span>
                AI ใช้เวลา 10–30 วินาที กรุณารอ...
            </div>

            <button type="submit"
                class="w-full bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-700 hover:to-purple-700 text-white font-semibold py-2.5 rounded-xl text-sm transition-all shadow-sm hover:shadow-lg hover:shadow-indigo-200 active:scale-[0.98]">
                🤖 วิเคราะห์ด้วย AI
            </button>
        </form>
    </div>

    {{-- Result --}}
    <div class="lg:col-span-2 space-y-5">
        @if(isset($result))
            @if(!$result['success'])
                <div class="glass-card p-6 border border-red-200 bg-red-50">
                    <p class="text-red-600 text-sm">{{ $result['error'] }}</p>
                </div>
            @else
                {{-- AI Summary banner --}}
                <div class="glass-card p-5 bg-gradient-to-r from-indigo-50 to-purple-50 border-indigo-100">
                    <div class="flex items-start gap-4">
                        <div class="w-10 h-10 bg-gradient-to-br from-indigo-500 to-purple-600 rounded-xl flex items-center justify-center shrink-0 shadow-sm">
                            <span class="text-lg">🤖</span>
                        </div>
                        <div class="flex-1">
                            <div class="flex items-center gap-2 mb-2">
                                <span class="font-bold text-slate-800">{{ $result['name'] }}</span>
                                @php
                                    $riskColor = $result['risk_score'] >= 7 ? 'bg-red-100 text-red-700' : ($result['risk_score'] >= 4 ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700');
                                @endphp
                                <span class="{{ $riskColor }} text-xs font-semibold px-2 py-0.5 rounded-full">
                                    Risk {{ $result['risk_score'] }}/10
                                </span>
                                <span class="text-xs text-slate-400">แสดงผลเป็น {{ $result['currency'] }}</span>
                            </div>
                            <p class="text-slate-600 text-sm leading-relaxed">{{ $result['summary'] }}</p>
                        </div>
                    </div>
                </div>

                {{-- Scenario cards --}}
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    @foreach([
                        'bull' => ['🚀', 'Bull', 'from-emerald-50 to-green-50', 'border-emerald-200', 'text-emerald-700', 'bg-emerald-500'],
                        'base' => ['📊', 'Base', 'from-indigo-50 to-blue-50', 'border-indigo-200', 'text-indigo-700', 'bg-indigo-500'],
                        'bear' => ['🐻', 'Bear', 'from-red-50 to-rose-50', 'border-red-200', 'text-red-600', 'bg-red-500'],
                    ] as $case => [$icon, $label, $bg, $border, $textColor, $dotColor])
                        @if(isset($result['projections'][$case]))
                        @php $d = $result['projections'][$case]; $isPos = $d['profit_loss_value'] >= 0; @endphp
                        <div class="glass-card bg-gradient-to-b {{ $bg }} border {{ $border }} p-5">
                            <div class="flex items-center gap-2 mb-3">
                                <span class="text-xl">{{ $icon }}</span>
                                <span class="font-bold {{ $textColor }} text-sm">{{ $label }} Case</span>
                            </div>
                            <div class="text-3xl font-bold {{ $textColor }} mb-3">{{ number_format($d['cagr'], 1) }}%</div>
                            <div class="space-y-1.5 text-xs">
                                <div class="flex justify-between text-slate-600">
                                    <span>ลงทุนรวม</span>
                                    <span class="font-medium">{{ number_format($d['total_invested'], 0) }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-slate-600">มูลค่าอนาคต</span>
                                    <span class="font-bold {{ $textColor }}">{{ number_format($d['future_value'], 0) }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-slate-600">กำไร/ขาดทุน</span>
                                    <span class="font-bold {{ $isPos ? 'text-emerald-600' : 'text-red-500' }}">
                                        {{ $isPos ? '+' : '' }}{{ number_format($d['profit_loss_percentage'], 1) }}%
                                    </span>
                                </div>
                            </div>
                            <p class="mt-3 pt-3 border-t border-slate-200/50 text-xs text-slate-500 leading-relaxed">{{ $d['rationale'] }}</p>
                        </div>
                        @endif
                    @endforeach
                </div>

                {{-- Chart --}}
                @php
                    $chartYears = range(0, $result['years']);
                    $initial   = $result['initial_amount'];
                    $monthly   = $result['monthly_amount'];

                    $makeData = function($cagr) use ($initial, $monthly, $chartYears) {
                        return collect($chartYears)->map(function($y) use ($initial, $monthly, $cagr) {
                            $months = $y * 12;
                            $r = ($cagr / 100) / 12;
                            if ($r == 0) return round($initial + $monthly * $months, 0);
                            $fvI = $initial * pow(1 + $r, $months);
                            $fvD = $monthly * ((pow(1 + $r, $months) - 1) / $r) * (1 + $r);
                            return round($fvI + $fvD, 0);
                        })->values()->all();
                    };

                    $invested  = collect($chartYears)->map(fn($y) => $initial + $monthly * 12 * $y)->values()->all();
                    $bullData  = $makeData($result['projections']['bull']['cagr']);
                    $baseData  = $makeData($result['projections']['base']['cagr']);
                    $bearData  = $makeData($result['projections']['bear']['cagr']);
                @endphp
                <div class="glass-card p-6">
                    <h3 class="font-semibold text-slate-800 mb-4">
                        กราฟการเติบโต {{ $result['years'] }} ปี
                        <span class="text-xs text-slate-400 font-normal ml-1">({{ $result['currency'] }})</span>
                    </h3>
                    <canvas id="projectionChart" height="90"></canvas>
                </div>
            @endif
        @else
            <div class="glass-card flex flex-col items-center justify-center py-20 text-center">
                <div class="w-16 h-16 bg-gradient-to-br from-indigo-100 to-purple-100 rounded-2xl flex items-center justify-center mb-4 text-3xl">🤖</div>
                <p class="text-slate-500 font-medium">กรอกข้อมูลแล้วกด "วิเคราะห์ด้วย AI"</p>
                <p class="text-slate-400 text-sm mt-1">ระบบจะใช้ Gemini AI วิเคราะห์แนวโน้มและคาดการณ์ผลตอบแทน</p>
            </div>
        @endif
    </div>
</div>

@endsection

@push('scripts')
<script>
function toggleExRate() {
    const currency = document.querySelector('[name=display_currency]:checked')?.value;
    const stockCurrency = '{{ $stock->currency }}';
    const box = document.getElementById('exRateBox');
    const labels = document.querySelectorAll('.currency-label');

    if (currency === 'THB') {
        labels.forEach(l => l.textContent = 'THB');
        if (stockCurrency === 'USD') box.classList.remove('hidden');
    } else {
        labels.forEach(l => l.textContent = 'USD');
        box.classList.add('hidden');
    }
}
// init
toggleExRate();
</script>

@if(isset($result) && $result['success'])
<script>
const labels   = @json(array_map(fn($y) => "ปี {$y}", $chartYears));
const invested = @json($invested);
const bull     = @json($bullData);
const base     = @json($baseData);
const bear     = @json($bearData);

new Chart(document.getElementById('projectionChart'), {
    type: 'line',
    data: {
        labels,
        datasets: [
            { label: 'เงินลงทุนสะสม', data: invested, borderColor: '#cbd5e1', borderDash: [5,3], borderWidth: 1.5, pointRadius: 0, fill: false },
            { label: '🚀 Bull', data: bull, borderColor: '#10b981', borderWidth: 2, pointRadius: 3, pointBackgroundColor: '#10b981', fill: false },
            { label: '📊 Base', data: base, borderColor: '#6366f1', borderWidth: 2.5, pointRadius: 3, pointBackgroundColor: '#6366f1', fill: false },
            { label: '🐻 Bear', data: bear, borderColor: '#f43f5e', borderWidth: 2, pointRadius: 3, pointBackgroundColor: '#f43f5e', fill: false },
        ]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { position: 'top', labels: { font: { size: 11 }, usePointStyle: true, pointStyleWidth: 10 } },
            tooltip: {
                callbacks: {
                    label: ctx => ` ${ctx.dataset.label}: ${ctx.parsed.y.toLocaleString('th-TH')} {{ $result['currency'] }}`
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
</script>
@endif
@endpush
