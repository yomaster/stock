@extends('layouts.app')

@section('title', 'เปรียบเทียบหุ้น — Stock AI')

@section('content')

<div class="mb-8">
    <h1 class="text-2xl font-bold text-slate-900">📊 เปรียบเทียบหุ้น</h1>
    <p class="text-slate-500 text-sm mt-1">เทียบการเติบโต (%) ของหลายหุ้นในช่วงเวลาเดียวกัน — ฐานเริ่มต้น = 100</p>
</div>

<div class="grid grid-cols-1 lg:grid-cols-4 gap-6">

    {{-- Form เลือกหุ้น --}}
    <div class="glass-card p-6 self-start">
        <h2 class="font-semibold text-slate-800 mb-4">เลือกหุ้น</h2>
        <form method="GET" action="{{ route('stocks.compare') }}">
            <div class="space-y-2 mb-4 max-h-72 overflow-y-auto pr-1">
                @foreach($allStocks as $stock)
                <label class="flex items-center gap-2.5 p-2.5 rounded-xl border border-slate-100 hover:bg-slate-50 cursor-pointer transition">
                    <input type="checkbox" name="symbols[]" value="{{ $stock->symbol }}"
                        {{ in_array($stock->symbol, $selected) ? 'checked' : '' }}
                        class="w-4 h-4 rounded text-indigo-600 border-slate-300 focus:ring-indigo-400">
                    <span class="text-sm font-medium text-slate-700">{{ $stock->symbol }}</span>
                    <span class="text-xs text-slate-400 truncate">{{ $stock->name }}</span>
                </label>
                @endforeach
            </div>

            <label class="block text-sm font-medium text-slate-600 mb-1.5">ช่วงเวลา</label>
            <div class="grid grid-cols-5 gap-1.5 mb-4">
                @foreach([1,2,3,5,10] as $y)
                <label class="cursor-pointer">
                    <input type="radio" name="years" value="{{ $y }}" {{ $years == $y ? 'checked' : '' }} class="peer sr-only">
                    <div class="border border-slate-200 peer-checked:border-indigo-500 peer-checked:bg-indigo-50 rounded-lg py-2 text-center text-xs font-medium text-slate-600 peer-checked:text-indigo-700 transition">
                        {{ $y }}ปี
                    </div>
                </label>
                @endforeach
            </div>

            <button type="submit"
                class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2.5 rounded-xl text-sm transition shadow-sm active:scale-[0.98]">
                เปรียบเทียบ
            </button>
        </form>
    </div>

    {{-- ผลลัพธ์ --}}
    <div class="lg:col-span-3 space-y-6">
        @if(empty($datasets))
            <div class="glass-card flex flex-col items-center justify-center py-20 text-center">
                <div class="w-16 h-16 bg-gradient-to-br from-indigo-100 to-purple-100 rounded-2xl flex items-center justify-center mb-4 text-3xl">📊</div>
                <p class="text-slate-500 font-medium">เลือกหุ้น 2 ตัวขึ้นไปแล้วกด "เปรียบเทียบ"</p>
                <p class="text-slate-400 text-sm mt-1">ระบบจะแสดงกราฟการเติบโตเทียบกัน</p>
            </div>
        @else
            {{-- Chart --}}
            <div class="glass-card p-6">
                <h3 class="font-semibold text-slate-800 mb-4">การเติบโตเทียบกัน ({{ $years }} ปี, ฐาน 100)</h3>
                <canvas id="compareChart" height="100"></canvas>
            </div>

            {{-- ตารางสรุป --}}
            <div class="glass-card p-6">
                <h3 class="font-semibold text-slate-800 mb-4">สรุปผลตอบแทน</h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs text-slate-500 uppercase border-b border-slate-200">
                                <th class="pb-2 pr-4">อันดับ</th>
                                <th class="pb-2 pr-4">หุ้น</th>
                                <th class="pb-2 pr-4 text-right">ราคาเริ่ม</th>
                                <th class="pb-2 pr-4 text-right">ราคาล่าสุด</th>
                                <th class="pb-2 text-right">ผลตอบแทน</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($summary as $i => $s)
                            <tr>
                                <td class="py-2.5 pr-4 text-slate-400">{{ $i + 1 }}</td>
                                <td class="py-2.5 pr-4">
                                    <span class="font-semibold text-slate-800">{{ $s['symbol'] }}</span>
                                    <span class="text-xs text-slate-400 block">{{ $s['name'] }}</span>
                                </td>
                                <td class="py-2.5 pr-4 text-right text-slate-600">{{ number_format($s['first'], 2) }}</td>
                                <td class="py-2.5 pr-4 text-right text-slate-600">{{ number_format($s['last'], 2) }}</td>
                                <td class="py-2.5 text-right font-bold {{ $s['return'] >= 0 ? 'text-emerald-600' : 'text-red-500' }}">
                                    {{ $s['return'] >= 0 ? '+' : '' }}{{ number_format($s['return'], 1) }}%
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>
</div>

@endsection

@push('scripts')
@if(!empty($datasets))
<script>
document.addEventListener('DOMContentLoaded', function () {
    const labels = @json($chartLabels);
    const series = @json($datasets);
    const palette = ['#6366f1','#10b981','#f43f5e','#f59e0b','#06b6d4','#a855f7','#ec4899','#84cc16'];

    const datasets = series.map((s, i) => ({
        label: s.symbol,
        data: s.data,
        borderColor: palette[i % palette.length],
        backgroundColor: 'transparent',
        borderWidth: 2,
        pointRadius: 0,
        pointHoverRadius: 4,
        spanGaps: true,
        tension: 0.1,
    }));

    new Chart(document.getElementById('compareChart'), {
        type: 'line',
        data: { labels, datasets },
        options: {
            responsive: true,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'top', labels: { font: { size: 11 }, usePointStyle: true, pointStyleWidth: 10 } },
                tooltip: {
                    callbacks: {
                        label: ctx => ` ${ctx.dataset.label}: ${ctx.parsed.y !== null ? (ctx.parsed.y - 100 >= 0 ? '+' : '') + (ctx.parsed.y - 100).toFixed(1) + '%' : '-'}`
                    }
                }
            },
            scales: {
                x: { ticks: { maxTicksLimit: 8, font: { size: 10 }, color: '#94a3b8' }, grid: { color: '#f1f5f9' } },
                y: {
                    ticks: { font: { size: 10 }, color: '#94a3b8', callback: v => v + '' },
                    grid: { color: '#f1f5f9' }
                }
            }
        }
    });
});
</script>
@endif
@endpush
