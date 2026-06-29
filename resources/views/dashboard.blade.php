@extends('layouts.app')

@section('title', 'Dashboard — Invest AI')

@section('content')

{{-- Hero --}}
<div class="mb-8">
    <h1 class="text-2xl font-bold text-slate-900">ภาพรวมตลาด</h1>
    <p class="text-slate-500 text-sm mt-1">อัปเดตล่าสุด {{ now()->format('d/m/Y H:i') }}</p>
</div>

{{-- Stats --}}
<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8">
    @foreach([
        ['icon' => '🏢', 'value' => number_format($stockCount), 'label' => 'สินทรัพย์ในระบบ', 'color' => 'indigo'],
        ['icon' => '📅', 'value' => number_format($priceCount), 'label' => 'ข้อมูลราคา', 'color' => 'violet'],
        ['icon' => '📰', 'value' => number_format($newsCount), 'label' => 'ข่าวในระบบ', 'color' => 'sky'],
    ] as $stat)
    <div class="glass-card p-5 flex items-center gap-4">
        <div class="text-3xl">{{ $stat['icon'] }}</div>
        <div>
            <div class="text-2xl font-bold text-slate-800">{{ $stat['value'] }}</div>
            <div class="text-xs text-slate-500 font-medium mt-0.5">{{ $stat['label'] }}</div>
        </div>
    </div>
    @endforeach
</div>

{{-- มูลค่าพอร์ตแยกตามชนิดสินทรัพย์ (รวมทุกพอร์ต) --}}
@if(!empty($assetBreakdown))
<div class="mb-8">
    <h2 class="font-semibold text-slate-800 mb-3">มูลค่าพอร์ตตามชนิดสินทรัพย์</h2>
    @php
        $catMeta = [
            'stock' => '📈 หุ้น',
            'etf'   => '📦 ETF',
            'fund'  => '🏦 กองทุนรวม',
            'gold'  => '🥇 ทองคำ',
        ];
    @endphp
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        @foreach($catMeta as $cat => $label)
            @continue(empty($assetBreakdown[$cat]))
            @php $b = $assetBreakdown[$cat]; @endphp
            <div class="glass-card p-5">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-sm font-semibold text-slate-700">{{ $label }}</span>
                    <span class="text-xs text-slate-400">{{ $b['count'] }} รายการ</span>
                </div>
                <div class="text-xl font-bold text-slate-800">
                    {{ number_format($b['value'], 2) }} <span class="text-xs font-normal text-slate-400">บาท</span>
                </div>
                <div class="text-xs text-slate-400 mt-0.5">ต้นทุน {{ number_format($b['cost'], 2) }}</div>
                <div class="text-sm font-medium mt-1 {{ $b['pnl'] >= 0 ? 'text-emerald-600' : 'text-red-500' }}">
                    {{ $b['pnl'] >= 0 ? '+' : '' }}{{ number_format($b['pnl'], 2) }}
                    ({{ $b['pnl'] >= 0 ? '+' : '' }}{{ number_format($b['pnl_pct'], 2) }}%)
                </div>
            </div>
        @endforeach
    </div>
</div>
@endif

@if($stockCount === 0)
    {{-- Empty state --}}
    <div class="glass-card p-12 text-center">
        <div class="text-6xl mb-4">🚀</div>
        <h2 class="text-lg font-semibold text-slate-700 mb-2">เริ่มต้นใช้งาน</h2>
        <p class="text-slate-400 text-sm mb-6">เพิ่มหุ้นที่สนใจเพื่อดึงข้อมูลและเริ่มวิเคราะห์</p>
        <a href="{{ route('manage.index') }}" class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white font-medium px-5 py-2.5 rounded-xl text-sm transition shadow-sm">
            + เพิ่มหุ้นแรก
        </a>
    </div>
@else
    <div class="grid grid-cols-1 lg:grid-cols-5 gap-6">
        {{-- Stock list (wider) --}}
        <div class="lg:col-span-3 glass-card p-6">
            <div class="flex items-center justify-between mb-5">
                <h2 class="font-semibold text-slate-800">สินทรัพย์ที่ติดตาม</h2>
                <a href="{{ route('stocks.index') }}" class="text-indigo-600 text-xs font-medium hover:underline">ดูทั้งหมด →</a>
            </div>
            @php
                $waSections = ['stock' => '📈 หุ้น', 'etf' => '📦 ETF', 'fund' => '🏦 กองทุนรวม', 'gold' => '🥇 ทองคำ'];
                $waGrouped  = collect($stocks)->groupBy('asset_category');
            @endphp
            <div class="space-y-5">
            @foreach($waSections as $cat => $label)
                @php $items = $waGrouped->get($cat); @endphp
                @continue(!$items || $items->isEmpty())
                <div>
                    <h3 class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-2">
                        {{ $label }} <span class="text-slate-400 font-normal">· {{ $items->count() }}</span>
                    </h3>
                    <div class="space-y-2">
                    @foreach($items as $s)
                    <a href="{{ route('stocks.show', $s['id']) }}"
                       class="flex items-center justify-between p-3 bg-white/50 border border-slate-100 rounded-xl hover:border-indigo-200 hover:bg-white transition group">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 bg-gradient-to-br from-indigo-100 to-purple-100 rounded-lg flex items-center justify-center shrink-0">
                                <span class="text-xs font-bold text-indigo-600">{{ substr($s['symbol'], 0, 2) }}</span>
                            </div>
                            <div>
                                <div class="text-sm font-semibold text-slate-800 group-hover:text-indigo-600 transition">{{ $s['symbol'] }}</div>
                                <div class="text-xs text-slate-400 truncate max-w-40">{{ $s['name'] }}</div>
                            </div>
                        </div>
                        <div class="text-right">
                            @if($s['price'])
                                <div class="text-sm font-semibold text-slate-800">{{ number_format($s['price'], 2) }}
                                    <span class="text-xs text-slate-400 font-normal">{{ $s['currency'] }}</span>
                                </div>
                                @if($s['change'] !== null)
                                    <div class="text-xs font-medium {{ $s['change'] >= 0 ? 'text-emerald-600' : 'text-red-500' }}">
                                        {{ $s['change'] >= 0 ? '▲' : '▼' }} {{ number_format(abs($s['change']), 2) }}%
                                    </div>
                                @endif
                            @else
                                <span class="text-xs text-slate-300">—</span>
                            @endif
                        </div>
                    </a>
                    @endforeach
                    </div>
                </div>
            @endforeach
            </div>
        </div>

        {{-- Right column --}}
        <div class="lg:col-span-2 space-y-6">

            {{-- ผลวิเคราะห์ AI (แยกรายคน) — แสดงเสมอ; ถ้ายังไม่มีโชว์ empty state (ข่าวไปดูที่หน้าหุ้นรายตัว) --}}
            <div class="glass-card p-6">
                <h2 class="font-semibold text-slate-800 mb-4">ผลวิเคราะห์ AI ล่าสุด</h2>
                @if($latestAnalyses->isNotEmpty())
                <div class="space-y-3">
                    @foreach($latestAnalyses as $a)
                    <div class="flex items-start gap-3 p-3 bg-white/50 rounded-xl border border-slate-100">
                        <div>
                            <div class="flex items-center gap-2 mb-1">
                                <a href="{{ route('stocks.show', $a->stock_id) }}" class="text-sm font-bold text-slate-800 hover:text-indigo-600">{{ $a->stock?->symbol }}</a>
                                @php
                                    $ratingColor = match($a->rating) {
                                        'Buy'   => 'bg-emerald-100 text-emerald-700',
                                        'Hold'  => 'bg-amber-100 text-amber-700',
                                        default => 'bg-red-100 text-red-700',
                                    };
                                @endphp
                                <span class="{{ $ratingColor }} text-xs font-semibold px-2 py-0.5 rounded-full">{{ $a->rating }}</span>
                                <span class="text-xs text-slate-400">Risk {{ $a->risk_score }}/10</span>
                            </div>
                            <p class="text-xs text-slate-500 line-clamp-2">{{ $a->summary }}</p>
                        </div>
                    </div>
                    @endforeach
                </div>
                @else
                {{-- empty state — ยังไม่เคยวิเคราะห์ --}}
                <div class="flex flex-col items-center justify-center py-12 text-center">
                    <div class="w-14 h-14 bg-gradient-to-br from-indigo-100 to-purple-100 rounded-2xl flex items-center justify-center mb-3 text-2xl">🤖</div>
                    <p class="text-slate-500 text-sm font-medium">ยังไม่มีผลวิเคราะห์ AI</p>
                    <p class="text-slate-400 text-xs mt-1 max-w-xs">ไปที่หน้าหุ้นรายตัว แล้วกดปุ่ม "AI วิเคราะห์" เพื่อเริ่มเก็บผลวิเคราะห์ของคุณ</p>
                    <a href="{{ route('stocks.index') }}" class="mt-4 inline-flex items-center gap-1 text-xs font-medium text-indigo-600 hover:text-indigo-700">
                        ดูหุ้นทั้งหมด →
                    </a>
                </div>
                @endif
            </div>

        </div>
    </div>
@endif

@endsection
