@extends('layouts.app')

@section('title', 'รายงานภาษีรายปี — InvestAI')

@section('content')

@php
    $realizedByYear = $report['realized_by_year'];
    $contribByYear  = $report['contrib_by_year'];
    $realizedDetail = $report['realized_detail'];
    $be = fn ($ce) => $ce + 543; // ค.ศ. → พ.ศ.
    $taxColors = ['RMF' => 'text-indigo-600', 'SSF' => 'text-emerald-600', 'ThaiESG' => 'text-amber-600'];
@endphp

<div class="mb-6">
    <h1 class="text-2xl font-bold text-slate-900">🧾 รายงานภาษีรายปี</h1>
    <p class="text-slate-500 text-sm mt-1">กำไร/ขาดทุนที่รับรู้ (จากการขาย) + ยอดลงทุนกองลดหย่อน RMF/SSF/ThaiESG — รวมทุกพอร์ต</p>
</div>

{{-- ══════ ยอดลงทุนกองลดหย่อน (RMF/SSF/ThaiESG) ══════ --}}
<div class="glass-card p-5 mb-6 overflow-x-auto">
    <div class="flex items-center justify-between mb-1">
        <h2 class="font-semibold text-slate-800 text-sm">💸 ยอดลงทุนกองลดหย่อนภาษี รายปี</h2>
        @if(!empty($contribByYear))
            <a href="{{ route('report.export', ['type' => 'contrib']) }}" class="text-xs text-indigo-600 hover:underline">⬇ CSV</a>
        @endif
    </div>
    <p class="text-[11px] text-amber-600 mb-3">⚠️ ตรวจประเภทกองอัตโนมัติจากรหัส — โปรดตรวจสอบกับ statement จริงก่อนยื่นภาษี</p>
    @if(empty($contribByYear))
        <p class="text-sm text-slate-400 py-4 text-center">ยังไม่มีรายการซื้อกอง RMF/SSF/ThaiESG</p>
    @else
        <table class="w-full text-sm min-w-[520px]">
            <thead>
                <tr class="text-xs text-slate-400 border-b border-slate-100">
                    <th class="text-left font-medium pb-2">ปีภาษี</th>
                    <th class="text-right font-medium pb-2">RMF</th>
                    <th class="text-right font-medium pb-2">SSF</th>
                    <th class="text-right font-medium pb-2">ThaiESG</th>
                    <th class="text-right font-medium pb-2">รวม</th>
                </tr>
            </thead>
            <tbody>
                @foreach($contribByYear as $ce => $c)
                    <tr class="border-b border-slate-50">
                        <td class="py-2.5 font-medium text-slate-700">{{ $be($ce) }}</td>
                        <td class="text-right {{ $c['RMF'] > 0 ? 'text-indigo-600' : 'text-slate-300' }}">{{ $c['RMF'] > 0 ? number_format($c['RMF'], 0) : '—' }}</td>
                        <td class="text-right {{ $c['SSF'] > 0 ? 'text-emerald-600' : 'text-slate-300' }}">{{ $c['SSF'] > 0 ? number_format($c['SSF'], 0) : '—' }}</td>
                        <td class="text-right {{ $c['ThaiESG'] > 0 ? 'text-amber-600' : 'text-slate-300' }}">{{ $c['ThaiESG'] > 0 ? number_format($c['ThaiESG'], 0) : '—' }}</td>
                        <td class="text-right font-semibold text-slate-800">{{ number_format($c['total'], 0) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>

{{-- ══════ Realized P/L รายปี ══════ --}}
<div class="glass-card p-5 mb-6 overflow-x-auto">
    <div class="flex items-center justify-between mb-1">
        <h2 class="font-semibold text-slate-800 text-sm">📈 กำไร/ขาดทุนที่รับรู้ (Realized) รายปี</h2>
        @if(!empty($realizedByYear))
            <a href="{{ route('report.export', ['type' => 'realized']) }}" class="text-xs text-indigo-600 hover:underline">⬇ CSV</a>
        @endif
    </div>
    <p class="text-[11px] text-slate-400 mb-3">คิดต้นทุนแบบ Average Cost (เดียวกับหน้าพอร์ต) · กำไรจากหุ้นไทย SET / กองทุนรวม ส่วนใหญ่ได้รับยกเว้นภาษี — ใช้เพื่อเก็บสถิติ</p>
    @if(empty($realizedByYear))
        <p class="text-sm text-slate-400 py-4 text-center">ยังไม่มีรายการขาย</p>
    @else
        <table class="w-full text-sm min-w-[520px]">
            <thead>
                <tr class="text-xs text-slate-400 border-b border-slate-100">
                    <th class="text-left font-medium pb-2">ปี</th>
                    <th class="text-right font-medium pb-2">จำนวนครั้ง</th>
                    <th class="text-right font-medium pb-2">เงินที่ได้</th>
                    <th class="text-right font-medium pb-2">ต้นทุน</th>
                    <th class="text-right font-medium pb-2">กำไร/ขาดทุน</th>
                </tr>
            </thead>
            <tbody>
                @foreach($realizedByYear as $ce => $y)
                    @php $yUp = ($y['pl'] ?? 0) >= 0; @endphp
                    <tr class="border-b border-slate-50">
                        <td class="py-2.5 font-medium text-slate-700">{{ $be($ce) }}</td>
                        <td class="text-right text-slate-500">{{ $y['count'] }}</td>
                        <td class="text-right text-slate-600">{{ number_format($y['proceeds'], 0) }}</td>
                        <td class="text-right text-slate-600">{{ number_format($y['cost'], 0) }}</td>
                        <td class="text-right font-semibold {{ $yUp ? 'text-emerald-600' : 'text-rose-600' }}">{{ $yUp ? '+' : '' }}{{ number_format($y['pl'], 0) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>

{{-- ══════ รายละเอียดการขาย ══════ --}}
@if(!empty($realizedDetail))
<div class="glass-card p-5 overflow-x-auto">
    <h2 class="font-semibold text-slate-800 text-sm mb-3">รายละเอียดการขาย (Realized)</h2>
    <table class="w-full text-sm min-w-[640px]">
        <thead>
            <tr class="text-xs text-slate-400 border-b border-slate-100">
                <th class="text-left font-medium pb-2">วันที่</th>
                <th class="text-left font-medium pb-2">สินทรัพย์</th>
                <th class="text-left font-medium pb-2">พอร์ต</th>
                <th class="text-right font-medium pb-2">เงินที่ได้</th>
                <th class="text-right font-medium pb-2">ต้นทุน</th>
                <th class="text-right font-medium pb-2">กำไร/ขาดทุน</th>
            </tr>
        </thead>
        <tbody>
            @foreach($realizedDetail as $d)
                @php $dUp = ($d['pl_thb'] ?? 0) >= 0; @endphp
                <tr class="border-b border-slate-50">
                    <td class="py-2 text-slate-500 whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($d['date'])->format('d/m/') }}{{ $be((int) substr($d['date'], 0, 4)) }}</td>
                    <td class="py-2"><span class="font-medium text-slate-800">{{ $d['symbol'] }}</span></td>
                    <td class="py-2 text-slate-400 text-xs">{{ $d['portfolio'] }}</td>
                    <td class="text-right text-slate-600">{{ number_format($d['proceeds_thb'], 0) }}</td>
                    <td class="text-right text-slate-600">{{ number_format($d['cost_thb'], 0) }}</td>
                    <td class="text-right font-medium {{ $dUp ? 'text-emerald-600' : 'text-rose-600' }}">{{ $dUp ? '+' : '' }}{{ number_format($d['pl_thb'], 0) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endif

@endsection
