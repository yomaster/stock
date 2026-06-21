@extends('layouts.app')

@section('title', 'DCA Backtest — ' . $stock->symbol)

@section('content')

<div class="mb-6">
    <a href="{{ route('stocks.show', $stock) }}" class="text-slate-400 hover:text-slate-600 text-xs font-medium">← {{ $stock->symbol }}</a>
    <h1 class="text-2xl font-bold text-slate-900 mt-2">DCA Backtest — {{ $stock->symbol }}</h1>
    <p class="text-slate-500 text-sm mt-0.5">จำลองการลงทุนแบบ DCA ย้อนหลัง พร้อมคิดเงินปันผลทบต้น</p>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    {{-- Form --}}
    <div class="glass-card p-6">
        <h2 class="font-semibold text-slate-800 mb-5">ตั้งค่าการจำลอง</h2>
        <form method="POST" action="{{ route('stocks.backtest.run', $stock) }}" class="space-y-4">
            @csrf

            <div>
                <label class="block text-sm font-medium text-slate-600 mb-1.5">
                    เงินลงทุนต่อเดือน <span class="text-slate-400">({{ $stock->currency }})</span>
                </label>
                <input type="number" name="monthly_amount" value="{{ old('monthly_amount', 5000) }}" min="100" step="100"
                    class="w-full border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-sky-400 focus:border-transparent bg-white/70">
                @error('monthly_amount') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-600 mb-1.5">ระยะเวลาย้อนหลัง</label>
                <div class="grid grid-cols-4 gap-1.5">
                    @foreach([1,3,5,7,10,15,20] as $y)
                    <label class="cursor-pointer">
                        <input type="radio" name="years" value="{{ $y }}" {{ old('years', 5) == $y ? 'checked' : '' }} class="peer sr-only">
                        <div class="border border-slate-200 peer-checked:border-sky-500 peer-checked:bg-sky-50 rounded-lg py-2 text-center text-xs font-medium text-slate-600 peer-checked:text-sky-700 transition hover:border-slate-300">
                            {{ $y }}ปี
                        </div>
                    </label>
                    @endforeach
                </div>
            </div>

            <div class="flex items-center gap-3 p-3.5 bg-slate-50 rounded-xl border border-slate-100">
                <input type="checkbox" name="reinvest_dividends" id="reinvest" value="1" {{ old('reinvest_dividends', true) ? 'checked' : '' }}
                    class="w-4 h-4 rounded text-sky-600 border-slate-300 focus:ring-sky-400">
                <label for="reinvest" class="text-sm text-slate-700 cursor-pointer">
                    นำเงินปันผลมาซื้อหุ้นทบต้น
                    <span class="text-xs text-slate-400 block">DRIP — Dividend Reinvestment Plan</span>
                </label>
            </div>

            <button type="submit"
                class="w-full bg-gradient-to-r from-sky-500 to-blue-600 hover:from-sky-600 hover:to-blue-700 text-white font-semibold py-2.5 rounded-xl text-sm transition-all shadow-sm hover:shadow-sky-200 hover:shadow-lg active:scale-[0.98]">
                📊 คำนวณ
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
                @php $isProfit = $result['profit_loss_value'] >= 0; @endphp

                {{-- Summary row --}}
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    @foreach([
                        ['ลงทุนสะสม', number_format($result['total_invested'], 0) . ' ' . $result['currency'], 'from-slate-50 to-slate-100', 'text-slate-700', 'border-slate-200'],
                        ['จำนวนหุ้น', number_format($result['total_shares'], 2), 'from-slate-50 to-slate-100', 'text-slate-700', 'border-slate-200'],
                        ['มูลค่าพอร์ต', number_format($result['portfolio_value'], 0) . ' ' . $result['currency'], $isProfit ? 'from-emerald-50 to-green-50' : 'from-red-50 to-rose-50', $isProfit ? 'text-emerald-700' : 'text-red-600', $isProfit ? 'border-emerald-200' : 'border-red-200'],
                        ['กำไร/ขาดทุน', ($isProfit ? '+' : '') . number_format($result['profit_loss_percentage'], 1) . '%', $isProfit ? 'from-emerald-50 to-green-50' : 'from-red-50 to-rose-50', $isProfit ? 'text-emerald-700' : 'text-red-600', $isProfit ? 'border-emerald-200' : 'border-red-200'],
                    ] as [$label, $val, $bg, $textColor, $border])
                    <div class="glass-card bg-gradient-to-b {{ $bg }} border {{ $border }} p-4 text-center">
                        <div class="text-xs text-slate-500 font-medium mb-1">{{ $label }}</div>
                        <div class="text-lg font-bold {{ $textColor }}">{{ $val }}</div>
                    </div>
                    @endforeach
                </div>

                {{-- Chart --}}
                @php
                    $buyTx = collect($result['transactions'])->where('type', '!=', 'dividend_reinvestment');
                    $chartLabels = $buyTx->pluck('date');
                    $chartInvested = []; $chartValue = []; $cum = 0;
                    foreach ($buyTx as $tx) {
                        $cum += $tx['amount_invested'];
                        $chartInvested[] = round($cum, 2);
                        $chartValue[]    = round($tx['total_shares'] * $result['latest_price'], 2);
                    }
                @endphp
                <div class="glass-card p-6">
                    <h3 class="font-semibold text-slate-800 mb-4">เงินลงทุนสะสม vs มูลค่าพอร์ต (ราคาปัจจุบัน)</h3>
                    <canvas id="backtestChart" height="90"></canvas>
                </div>

                {{-- Dividend log --}}
                @if(!empty($result['dividends']))
                <div class="glass-card p-6">
                    <h3 class="font-semibold text-slate-800 mb-3">
                        ประวัติเงินปันผล
                        <span class="text-xs text-slate-400 font-normal ml-1">{{ count($result['dividends']) }} ครั้ง · รับรวม {{ number_format($result['total_dividends_received'], 2) }} {{ $result['currency'] }}</span>
                    </h3>
                    <div class="overflow-auto max-h-48">
                        <table class="w-full text-xs">
                            <thead class="sticky top-0 bg-white/90">
                                <tr class="text-slate-400 uppercase border-b border-slate-100 text-left">
                                    <th class="pb-2 pr-4">วันที่</th>
                                    <th class="pb-2 pr-4 text-right">ปันผล/หุ้น</th>
                                    <th class="pb-2 text-right">รับเงิน</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-50">
                                @foreach($result['dividends'] as $div)
                                <tr>
                                    <td class="py-1.5 pr-4 text-slate-600">{{ $div['date'] }}</td>
                                    <td class="py-1.5 pr-4 text-right text-slate-600">{{ number_format($div['dividend_per_share'], 4) }}</td>
                                    <td class="py-1.5 text-right text-emerald-600 font-semibold">+{{ number_format($div['amount_received'], 2) }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
                @endif
            @endif
        @else
            <div class="glass-card flex flex-col items-center justify-center py-20 text-center">
                <div class="w-16 h-16 bg-gradient-to-br from-sky-100 to-blue-100 rounded-2xl flex items-center justify-center mb-4 text-3xl">📊</div>
                <p class="text-slate-500 font-medium">กรอกข้อมูลแล้วกด "คำนวณ"</p>
                <p class="text-slate-400 text-sm mt-1">ระบบจะจำลองการลงทุน DCA ย้อนหลังจากข้อมูลจริง</p>
            </div>
        @endif
    </div>
</div>

@endsection

@push('scripts')
@if(isset($result) && $result['success'])
<script>
document.addEventListener('DOMContentLoaded', function () {
const labels   = @json($chartLabels->values());
const invested = @json(array_values($chartInvested));
const value    = @json(array_values($chartValue));

new Chart(document.getElementById('backtestChart'), {
    type: 'line',
    data: {
        labels,
        datasets: [
            {
                label: 'เงินลงทุนสะสม', data: invested,
                borderColor: '#cbd5e1', borderDash: [5,3], borderWidth: 1.5,
                pointRadius: 0, fill: true, backgroundColor: 'rgba(203,213,225,0.1)'
            },
            {
                label: 'มูลค่าพอร์ต', data: value,
                borderColor: '#6366f1', borderWidth: 2,
                pointRadius: 0, fill: true, backgroundColor: 'rgba(99,102,241,0.08)'
            }
        ]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { position: 'top', labels: { font: { size: 11 }, usePointStyle: true, pointStyleWidth: 10 } }
        },
        scales: {
            x: { ticks: { maxTicksLimit: 8, font: { size: 10 }, color: '#94a3b8' }, grid: { color: '#f1f5f9' } },
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
});
</script>
@endif
@endpush
