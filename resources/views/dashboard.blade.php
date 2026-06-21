@extends('layouts.app')

@section('title', 'Dashboard — Stock AI')

@section('content')

{{-- Hero --}}
<div class="mb-8">
    <h1 class="text-2xl font-bold text-slate-900">ภาพรวมตลาด</h1>
    <p class="text-slate-500 text-sm mt-1">อัปเดตล่าสุด {{ now()->format('d/m/Y H:i') }}</p>
</div>

{{-- Stats --}}
<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8">
    @foreach([
        ['icon' => '🏢', 'value' => number_format($stockCount), 'label' => 'หุ้นในระบบ', 'color' => 'indigo'],
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
                <h2 class="font-semibold text-slate-800">หุ้นในพอร์ต</h2>
                <a href="{{ route('stocks.index') }}" class="text-indigo-600 text-xs font-medium hover:underline">ดูทั้งหมด →</a>
            </div>
            <div class="space-y-2">
                @foreach($stocks as $s)
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

        {{-- Right column --}}
        <div class="lg:col-span-2 space-y-6">

            {{-- AI Analyses --}}
            @if($latestAnalyses->isNotEmpty())
            <div class="glass-card p-6">
                <h2 class="font-semibold text-slate-800 mb-4">ผลวิเคราะห์ AI ล่าสุด</h2>
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
            </div>
            @endif

            {{-- News --}}
            <div class="glass-card p-6">
                <h2 class="font-semibold text-slate-800 mb-4">ข่าวล่าสุด</h2>
                @if($latestNews->isEmpty())
                    <p class="text-slate-400 text-xs">ยังไม่มีข่าว</p>
                @else
                    <div class="space-y-3">
                        @foreach($latestNews as $news)
                        <div class="border-b border-slate-100 pb-3 last:border-0 last:pb-0">
                            <a href="{{ $news->url }}" target="_blank" rel="noopener"
                               class="text-sm text-slate-700 hover:text-indigo-600 font-medium line-clamp-2 transition">
                                {{ $news->title }}
                            </a>
                            <div class="text-xs text-slate-400 mt-1">
                                {{ $news->source }} · {{ \Carbon\Carbon::parse($news->published_at)->diffForHumans() }}
                            </div>
                        </div>
                        @endforeach
                    </div>
                @endif
            </div>

        </div>
    </div>
@endif

@endsection
