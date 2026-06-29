@extends('layouts.app')

@section('title', $stock->symbol . ' — Invest AI')

@section('content')

{{-- Header --}}
<div class="flex flex-wrap items-start justify-between gap-4 mb-8">
    <div>
        <a href="{{ route('stocks.index') }}" class="text-slate-400 hover:text-slate-600 text-xs font-medium">← หุ้นทั้งหมด</a>
        <div class="flex items-center gap-3 mt-2">
            <div class="w-11 h-11 bg-gradient-to-br from-indigo-500 to-purple-600 rounded-xl flex items-center justify-center shadow-sm">
                <span class="text-sm font-bold text-white">{{ substr($stock->symbol, 0, 2) }}</span>
            </div>
            <div>
                <h1 class="text-2xl font-bold text-slate-900">{{ $stock->symbol }}</h1>
                <p class="text-slate-400 text-sm">{{ $stock->name }} · {{ $stock->exchange }}</p>
            </div>
        </div>
    </div>
    <div class="text-right">
        @if($latestPrice)
            <div class="text-3xl font-bold text-slate-900">{{ number_format($latestPrice->close, 2) }}
                <span class="text-lg text-slate-400 font-normal">{{ $stock->currency }}</span>
            </div>
            <div class="text-xs text-slate-400">{{ \Carbon\Carbon::parse($latestPrice->date)->format('d/m/Y') }}</div>
        @endif
        <div class="flex gap-2 mt-3 justify-end">
            <a href="{{ route('stocks.backtest', $stock) }}"
               class="flex items-center gap-1.5 bg-sky-500 hover:bg-sky-600 text-white px-4 py-2 rounded-xl text-sm font-medium transition shadow-sm">
                📊 DCA Backtest
            </a>
            <a href="{{ route('stocks.analyze', $stock) }}"
               class="flex items-center gap-1.5 bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-xl text-sm font-medium transition shadow-sm">
                🤖 AI วิเคราะห์
            </a>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    {{-- Price Chart --}}
    <div class="lg:col-span-2 glass-card p-6">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
            <h2 class="font-semibold text-slate-800">กราฟราคา</h2>
            @if(!$prices->isEmpty())
            <div id="rangeFilter" class="flex gap-1 bg-slate-100 rounded-lg p-1">
                @foreach(['1Y'=>'1ปี','2Y'=>'2ปี','3Y'=>'3ปี','4Y'=>'4ปี','5Y'=>'5ปี','10Y'=>'10ปี'] as $val => $label)
                <button type="button" data-range="{{ $val }}"
                        class="range-btn px-2.5 py-1 rounded-md text-xs font-medium text-slate-500 hover:text-slate-700 transition {{ $val === '1Y' ? 'bg-white text-indigo-600 shadow-sm' : '' }}">
                    {{ $label }}
                </button>
                @endforeach
            </div>
            @endif
        </div>
        @if($prices->isEmpty())
            <div class="text-slate-300 text-center py-12">ไม่มีข้อมูลราคา</div>
        @else
            <canvas id="priceChart" height="120"></canvas>
        @endif
    </div>

    {{-- AI Analysis Card --}}
    <div class="glass-card p-6">
        <h2 class="font-semibold text-slate-800 mb-4 flex items-center gap-2">
            <span>🤖</span> ผลวิเคราะห์ AI
        </h2>
        @if($analysis)
            @php
                $ratingColor = match($analysis->rating) {
                    'Buy'   => 'bg-emerald-100 text-emerald-700',
                    'Hold'  => 'bg-amber-100 text-amber-700',
                    default => 'bg-red-100 text-red-700',
                };
                $riskColor = $analysis->risk_score >= 7 ? 'text-red-500' : ($analysis->risk_score >= 4 ? 'text-amber-500' : 'text-emerald-600');
            @endphp
            <div class="space-y-3">
                <div class="flex justify-between items-center">
                    <span class="text-xs text-slate-500">Rating</span>
                    <span class="{{ $ratingColor }} text-xs font-semibold px-3 py-1 rounded-full">{{ $analysis->rating }}</span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-xs text-slate-500">ความเสี่ยง</span>
                    <span class="{{ $riskColor }} text-xl font-bold">{{ $analysis->risk_score }}<span class="text-sm text-slate-400">/10</span></span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-xs text-slate-500">สไตล์</span>
                    <span class="text-slate-700 text-xs font-medium bg-slate-100 px-2 py-0.5 rounded-full">{{ $analysis->investment_style }}</span>
                </div>
                <div class="pt-3 border-t border-slate-100">
                    <p class="text-slate-600 text-xs leading-relaxed">{{ $analysis->summary }}</p>
                    <p class="text-slate-300 text-xs mt-2">วิเคราะห์เมื่อ {{ \Carbon\Carbon::parse($analysis->date)->format('d/m/Y') }}</p>
                </div>
            </div>

            @if($analysis->projection_details)
                @php $proj = json_decode($analysis->projection_details, true); @endphp
                <div class="mt-4 pt-4 border-t border-slate-100 space-y-2">
                    @foreach(['bull' => ['🚀', 'text-emerald-600'], 'base' => ['📊', 'text-indigo-600'], 'bear' => ['🐻', 'text-red-500']] as $case => [$icon, $color])
                        @if(isset($proj[$case]))
                        <div class="bg-slate-50 rounded-xl p-3">
                            <div class="flex justify-between items-center mb-1">
                                <span class="text-xs font-semibold {{ $color }}">{{ $icon }} {{ ucfirst($case) }} Case</span>
                                <span class="text-xs font-bold {{ $color }}">{{ number_format($proj[$case]['cagr'], 1) }}%</span>
                            </div>
                            <p class="text-xs text-slate-400">{{ $proj[$case]['rationale'] ?? '' }}</p>
                        </div>
                        @endif
                    @endforeach
                </div>
            @endif
        @else
            <div class="text-center py-10">
                <p class="text-slate-400 text-sm mb-4">ยังไม่มีผลวิเคราะห์</p>
                <a href="{{ route('stocks.analyze', $stock) }}"
                   class="inline-block bg-purple-600 text-white px-4 py-2 rounded-xl text-sm font-medium hover:bg-purple-700 transition">
                    วิเคราะห์ด้วย AI
                </a>
            </div>
        @endif
    </div>

    {{-- News --}}
    <div class="lg:col-span-3 glass-card p-6">
        <div class="flex items-center justify-between mb-4">
            <h2 class="font-semibold text-slate-800">📰 {{ $newsIsFallback ? 'ข่าวตลาดโดยรวม' : 'ข่าวที่เกี่ยวข้อง' }}</h2>
            @if($newsIsFallback)
                <span class="text-xs text-amber-600 bg-amber-50 border border-amber-100 px-2.5 py-1 rounded-full">ยังไม่มีข่าวเจาะจง {{ $stock->symbol }} — แสดงข่าวตลาดแทน</span>
            @endif
        </div>
        @if($news->isEmpty())
            <p class="text-slate-300 text-sm">ไม่มีข่าวในระบบ</p>
        @else
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
                @foreach($news as $n)
                <a href="{{ $n->url }}" target="_blank" rel="noopener"
                   class="flex flex-col justify-between p-4 bg-white/50 border border-slate-100 rounded-xl hover:border-indigo-200 hover:bg-indigo-50/40 transition group">
                    <div>
                        <p class="text-sm font-medium text-slate-700 group-hover:text-indigo-700 line-clamp-2 mb-1 transition">{{ $n->title_th ?? $n->title }}</p>
                        @if($n->summary_th)
                            <p class="text-xs text-slate-500 line-clamp-2 mb-2">{{ $n->summary_th }}</p>
                        @endif
                    </div>
                    <div class="text-xs text-slate-400">{{ $n->source }} · {{ \Carbon\Carbon::parse($n->published_at)->diffForHumans() }}</div>
                </a>
                @endforeach
            </div>
        @endif
    </div>
</div>

@endsection

@push('scripts')
@if(!$prices->isEmpty())
<script>
document.addEventListener('DOMContentLoaded', function () {
// ข้อมูลราคา 10 ปีเต็ม (เรียงเก่า→ใหม่) — กรองช่วงปีฝั่ง client
const allDates  = @json($prices->pluck('date'));
const allCloses = @json($prices->pluck('close'));
const currency  = '{{ $stock->currency }}';

const priceChart = new Chart(document.getElementById('priceChart'), {
    type: 'line',
    data: {
        labels: allDates,
        datasets: [{
            label: '{{ $stock->symbol }}',
            data: allCloses,
            borderColor: '#6366f1',
            backgroundColor: 'rgba(99,102,241,0.06)',
            borderWidth: 1.5,
            pointRadius: 0,
            pointHoverRadius: 4,
            pointHoverBackgroundColor: '#6366f1',
            fill: true,
            tension: 0.2
        }]
    },
    options: {
        responsive: true,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: {
                    title: items => items[0].label,
                    label: ctx => ` ${ctx.parsed.y.toLocaleString('th-TH', {minimumFractionDigits: 2, maximumFractionDigits: 2})} ${currency}`
                }
            }
        },
        scales: {
            x: { ticks: { maxTicksLimit: 8, font: { size: 10 }, color: '#94a3b8' }, grid: { color: '#f1f5f9' } },
            y: { ticks: { font: { size: 10 }, color: '#94a3b8' }, grid: { color: '#f1f5f9' } }
        }
    }
});

// ฟิลเตอร์ช่วงปี — ตัดข้อมูลตามจำนวนปีย้อนหลังจากวันล่าสุด
function applyRange(years) {
    const cutoff = new Date();
    cutoff.setFullYear(cutoff.getFullYear() - years);
    const cutoffStr = cutoff.toISOString().slice(0, 10);

    const dates = [], closes = [];
    for (let i = 0; i < allDates.length; i++) {
        if (allDates[i] >= cutoffStr) {
            dates.push(allDates[i]);
            closes.push(allCloses[i]);
        }
    }
    priceChart.data.labels = dates;
    priceChart.data.datasets[0].data = closes;
    priceChart.update();
}

document.querySelectorAll('.range-btn').forEach(btn => {
    btn.addEventListener('click', function () {
        const years = parseInt(this.dataset.range);
        applyRange(years);
        // อัปเดต active state
        document.querySelectorAll('.range-btn').forEach(b => b.classList.remove('bg-white', 'text-indigo-600', 'shadow-sm'));
        this.classList.add('bg-white', 'text-indigo-600', 'shadow-sm');
    });
});

// เริ่มต้นแสดง 1 ปี
applyRange(1);
});
</script>
@endif
@endpush
