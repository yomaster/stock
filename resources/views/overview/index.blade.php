@extends('layouts.app')

@section('title', 'ภาพรวมรวมทุกพอร์ต — InvestAI')

@section('content')

@php
    $t = $overview['totals'];
    $up = ($t['unrealized_pl_thb'] ?? 0) >= 0;
    $pfCatLabels = ['stock' => '📈 หุ้น', 'etf' => '📦 ETF', 'fund' => '🏦 กองทุน', 'gold' => '🥇 ทองคำ', 'mixed' => '🧺 ผสม'];
    $catLabels = ['stock' => 'หุ้น', 'etf' => 'ETF', 'fund' => 'กองทุน', 'gold' => 'ทองคำ'];
@endphp

<div class="mb-6">
    <h1 class="text-2xl font-bold text-slate-900">📊 ภาพรวมรวมทุกพอร์ต</h1>
    <p class="text-slate-500 text-sm mt-1">รวมมูลค่า/กำไรจากทุกพอร์ตของคุณ ({{ $t['portfolios_count'] }} พอร์ต) · เรท USD→THB ≈ {{ number_format($rate, 2) }}</p>
</div>

@if(($t['portfolios_count'] ?? 0) === 0 || ($t['value_thb'] ?? 0) <= 0)
    <div class="glass-card flex flex-col items-center justify-center py-24 text-center">
        <div class="w-16 h-16 bg-gradient-to-br from-indigo-100 to-purple-100 rounded-2xl flex items-center justify-center mb-4 text-3xl">📊</div>
        <p class="text-slate-600 font-medium">ยังไม่มีสินทรัพย์ในพอร์ต</p>
        <p class="text-slate-400 text-sm mt-1">เพิ่มหุ้น/กองทุน/ทองในพอร์ตก่อน แล้วกลับมาดูภาพรวมที่นี่</p>
        <a href="{{ route('portfolio.index') }}" class="mt-4 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium px-5 py-2.5 rounded-xl transition">ไปที่พอร์ต</a>
    </div>
@else
    {{-- การ์ดสรุปรวม --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
        <div class="glass-card p-4">
            <p class="text-xs text-slate-500">มูลค่ารวม</p>
            <p class="text-xl font-bold text-slate-900 mt-1">{{ number_format($t['value_thb'], 0) }}</p>
            <p class="text-[11px] text-slate-400">บาท</p>
        </div>
        <div class="glass-card p-4">
            <p class="text-xs text-slate-500">ต้นทุนรวม</p>
            <p class="text-xl font-bold text-slate-700 mt-1">{{ number_format($t['cost_thb'], 0) }}</p>
            <p class="text-[11px] text-slate-400">บาท</p>
        </div>
        <div class="glass-card p-4">
            <p class="text-xs {{ $up ? 'text-emerald-600' : 'text-rose-600' }}">กำไร/ขาดทุน (ยังไม่รับรู้)</p>
            <p class="text-xl font-bold {{ $up ? 'text-emerald-700' : 'text-rose-700' }} mt-1">{{ $up ? '+' : '' }}{{ number_format($t['unrealized_pl_thb'], 0) }}</p>
            <p class="text-[11px] {{ $up ? 'text-emerald-500' : 'text-rose-500' }}">{{ $up ? '+' : '' }}{{ number_format($t['unrealized_pl_pct'], 2) }}%</p>
        </div>
        <div class="glass-card p-4">
            <p class="text-xs text-slate-500">กำไรที่รับรู้แล้ว</p>
            @php $realUp = ($t['realized_pl_thb'] ?? 0) >= 0; @endphp
            <p class="text-xl font-bold {{ $realUp ? 'text-emerald-700' : 'text-rose-700' }} mt-1">{{ $realUp ? '+' : '' }}{{ number_format($t['realized_pl_thb'], 0) }}</p>
            <p class="text-[11px] text-slate-400">บาท (จากการขาย)</p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
        {{-- สัดส่วนตามชนิดสินทรัพย์ + กราฟ --}}
        <div class="glass-card p-5">
            <h2 class="font-semibold text-slate-800 text-sm mb-3">สัดส่วนตามชนิดสินทรัพย์</h2>
            <div class="relative mx-auto" style="height:200px; max-width:200px">
                <canvas id="catChart"></canvas>
            </div>
            <div class="mt-4 space-y-1.5">
                @foreach($overview['by_category'] as $cat => $b)
                    @php $cUp = ($b['pnl_pct'] ?? 0) >= 0; @endphp
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-slate-600">{{ $pfCatLabels[$cat] ?? $cat }}</span>
                        <span class="text-slate-500">{{ number_format($b['allocation'], 1) }}% · <span class="{{ $cUp ? 'text-emerald-600' : 'text-rose-600' }}">{{ $cUp ? '+' : '' }}{{ number_format($b['pnl_pct'], 1) }}%</span></span>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- ตารางรายพอร์ต --}}
        <div class="glass-card p-5 lg:col-span-2 overflow-x-auto">
            <h2 class="font-semibold text-slate-800 text-sm mb-3">แต่ละพอร์ต</h2>
            <table class="w-full text-sm min-w-[560px]">
                <thead>
                    <tr class="text-xs text-slate-400 border-b border-slate-100">
                        <th class="text-left font-medium pb-2">พอร์ต</th>
                        <th class="text-right font-medium pb-2">มูลค่า</th>
                        <th class="text-right font-medium pb-2">สัดส่วน</th>
                        <th class="text-right font-medium pb-2">กำไร %</th>
                        <th class="text-right font-medium pb-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($overview['portfolios'] as $p)
                        @php $pUp = ($p['unrealized_pl_pct'] ?? 0) >= 0; @endphp
                        <tr class="border-b border-slate-50">
                            <td class="py-2.5">
                                <div class="font-medium text-slate-800">{{ $p['name'] }}</div>
                                <div class="text-xs text-slate-400">
                                    {{ $p['positions_count'] }} รายการ
                                    @if($p['category']) · {{ $pfCatLabels[$p['category']] ?? $p['category'] }} @endif
                                </div>
                            </td>
                            <td class="text-right text-slate-700">{{ number_format($p['value_thb'], 0) }}</td>
                            <td class="text-right text-slate-500">{{ number_format($p['allocation'], 1) }}%</td>
                            <td class="text-right font-medium {{ $pUp ? 'text-emerald-600' : 'text-rose-600' }}">{{ $pUp ? '+' : '' }}{{ number_format($p['unrealized_pl_pct'], 1) }}%</td>
                            <td class="text-right">
                                <a href="{{ route('portfolio.portfolios.switch', $p['id']) }}" class="text-xs text-indigo-600 hover:underline">เปิด →</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- สินทรัพย์รวมข้ามพอร์ต --}}
    <div class="glass-card p-5 overflow-x-auto">
        <h2 class="font-semibold text-slate-800 text-sm mb-1">สินทรัพย์รวม (ข้ามพอร์ต)</h2>
        <p class="text-xs text-slate-400 mb-3">รวมสินทรัพย์เดียวกันจากทุกพอร์ตเข้าด้วยกัน</p>
        <table class="w-full text-sm min-w-[560px]">
            <thead>
                <tr class="text-xs text-slate-400 border-b border-slate-100">
                    <th class="text-left font-medium pb-2">สินทรัพย์</th>
                    <th class="text-right font-medium pb-2">มูลค่า</th>
                    <th class="text-right font-medium pb-2">สัดส่วน</th>
                    <th class="text-right font-medium pb-2">กำไร %</th>
                </tr>
            </thead>
            <tbody>
                @foreach($overview['top_holdings'] as $h)
                    @php $hUp = ($h['unrealized_pl_pct'] ?? 0) >= 0; @endphp
                    <tr class="border-b border-slate-50">
                        <td class="py-2.5">
                            <div class="font-medium text-slate-800">{{ $h['symbol'] }} <span class="text-xs text-slate-400 font-normal">{{ $catLabels[$h['asset_category']] ?? '' }}</span></div>
                            <div class="text-xs text-slate-400 truncate max-w-[220px]">{{ $h['name'] }}</div>
                        </td>
                        <td class="text-right text-slate-700">{{ number_format($h['value_thb'], 0) }}</td>
                        <td class="text-right text-slate-500">{{ number_format($h['allocation'], 1) }}%</td>
                        <td class="text-right font-medium {{ $hUp ? 'text-emerald-600' : 'text-rose-600' }}">{{ $hUp ? '+' : '' }}{{ number_format($h['unrealized_pl_pct'], 1) }}%</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const el = document.getElementById('catChart');
    if (!el || !window.Chart) return;
    const cats = @json(array_map(fn ($k) => $catLabels[$k] ?? $k, array_keys($overview['by_category'])));
    const vals = @json(array_values(array_map(fn ($b) => round($b['value'], 2), $overview['by_category'])));
    const colorMap = { 'หุ้น': '#6366f1', 'ETF': '#0ea5e9', 'กองทุน': '#10b981', 'ทองคำ': '#f59e0b' };
    new Chart(el, {
        type: 'doughnut',
        data: {
            labels: cats,
            datasets: [{ data: vals, backgroundColor: cats.map(c => colorMap[c] || '#94a3b8'), borderWidth: 0 }],
        },
        options: {
            responsive: true, maintainAspectRatio: false, cutout: '62%',
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: ctx => ' ' + ctx.label + ': ' + ctx.parsed.toLocaleString('th-TH') + ' บาท' } }
            }
        }
    });
});
</script>
@endpush
