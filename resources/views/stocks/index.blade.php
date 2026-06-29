@extends('layouts.app')

@section('title', 'สินทรัพย์ทั้งหมด — Invest AI')

@section('content')

<div class="flex items-center justify-between mb-8">
    <div>
        <h1 class="text-2xl font-bold text-slate-900">สินทรัพย์ทั้งหมด</h1>
        <p class="text-slate-500 text-sm mt-1">{{ $stocks->count() }} สินทรัพย์ในระบบ</p>
    </div>
    <a href="{{ route('manage.index') }}"
       class="flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white font-medium px-4 py-2 rounded-xl text-sm transition shadow-sm">
        + เพิ่มสินทรัพย์
    </a>
</div>

@if($stocks->isEmpty())
    <div class="glass-card p-16 text-center">
        <div class="text-5xl mb-3">📭</div>
        <p class="text-slate-400">ยังไม่มีสินทรัพย์</p>
        <a href="{{ route('manage.index') }}" class="mt-4 inline-block text-indigo-600 text-sm font-medium hover:underline">+ เพิ่มสินทรัพย์แรก</a>
    </div>
@else
    <div class="glass-card overflow-hidden p-0">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50/80 border-b border-slate-200">
                    <tr class="text-left text-xs text-slate-500 font-semibold uppercase tracking-wide">
                        <th class="px-5 py-3.5">Symbol</th>
                        <th class="px-5 py-3.5">ชื่อบริษัท</th>
                        <th class="px-5 py-3.5">ตลาด</th>
                        <th class="px-5 py-3.5 text-right">ราคาล่าสุด</th>
                        <th class="px-5 py-3.5 text-right">เปลี่ยนแปลง</th>
                        <th class="px-5 py-3.5">Rating</th>
                        <th class="px-5 py-3.5">Risk</th>
                        <th class="px-5 py-3.5">Style</th>
                        <th class="px-5 py-3.5"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @php
                        $idxSections = ['stock' => '📈 หุ้น', 'etf' => '📦 ETF', 'fund' => '🏦 กองทุนรวม', 'gold' => '🥇 ทองคำ'];
                        $idxGrouped  = collect($stocks)->groupBy('asset_category');
                    @endphp
                    @foreach($idxSections as $cat => $secLabel)
                        @php $items = $idxGrouped->get($cat); @endphp
                        @continue(!$items || $items->isEmpty())
                        <tr class="bg-slate-50/70">
                            <td colspan="9" class="px-5 py-2 text-xs font-semibold text-slate-500 uppercase tracking-wide">
                                {{ $secLabel }} <span class="text-slate-400 font-normal">· {{ $items->count() }} รายการ</span>
                            </td>
                        </tr>
                    @foreach($items as $s)
                    <tr class="hover:bg-indigo-50/30 transition group">
                        <td class="px-5 py-3.5">
                            <a href="{{ route('assets.show', $s['id']) }}" class="font-bold text-indigo-600 hover:text-indigo-800 text-base">{{ $s['symbol'] }}</a>
                        </td>
                        <td class="px-5 py-3.5 text-slate-600 text-xs max-w-44 truncate">{{ $s['name'] ?? '—' }}</td>
                        <td class="px-5 py-3.5 text-slate-400 text-xs">{{ $s['exchange'] ?? '—' }}</td>
                        <td class="px-5 py-3.5 text-right font-semibold text-slate-800">
                            @if($s['price'])
                                {{ number_format($s['price'], 2) }}
                                <span class="text-xs text-slate-400 font-normal">{{ $s['currency'] }}</span>
                            @else <span class="text-slate-300">—</span> @endif
                        </td>
                        <td class="px-5 py-3.5 text-right">
                            @if($s['change'] !== null)
                                <span class="font-semibold text-xs {{ $s['change'] >= 0 ? 'text-emerald-600' : 'text-red-500' }}">
                                    {{ $s['change'] >= 0 ? '▲ +' : '▼ ' }}{{ number_format($s['change'], 2) }}%
                                </span>
                            @else <span class="text-slate-300">—</span> @endif
                        </td>
                        <td class="px-5 py-3.5">
                            @if($s['rating'])
                                @php $rc = match($s['rating']) { 'Buy' => 'bg-emerald-100 text-emerald-700', 'Hold' => 'bg-amber-100 text-amber-700', default => 'bg-red-100 text-red-700' }; @endphp
                                <span class="{{ $rc }} text-xs font-semibold px-2.5 py-0.5 rounded-full">{{ $s['rating'] }}</span>
                            @else <span class="text-slate-300 text-xs">—</span> @endif
                        </td>
                        <td class="px-5 py-3.5">
                            @if($s['risk_score'])
                                @php $riskColor = $s['risk_score'] >= 7 ? 'text-red-500' : ($s['risk_score'] >= 4 ? 'text-amber-500' : 'text-emerald-600'); @endphp
                                <span class="{{ $riskColor }} font-bold text-xs">{{ $s['risk_score'] }}/10</span>
                            @else <span class="text-slate-300">—</span> @endif
                        </td>
                        <td class="px-5 py-3.5 text-slate-400 text-xs">{{ $s['investment_style'] ?? '—' }}</td>
                        <td class="px-5 py-3.5">
                            <div class="flex gap-1.5 opacity-0 group-hover:opacity-100 transition">
                                <a href="{{ route('assets.backtest', $s['id']) }}"
                                   class="px-2.5 py-1 bg-sky-50 text-sky-700 hover:bg-sky-100 rounded-lg text-xs font-medium transition">DCA</a>
                                <a href="{{ route('assets.analyze', $s['id']) }}"
                                   class="px-2.5 py-1 bg-purple-50 text-purple-700 hover:bg-purple-100 rounded-lg text-xs font-medium transition">AI</a>
                            </div>
                        </td>
                    </tr>
                    @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

@endsection
