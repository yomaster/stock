@php
    // $plan = Plan ที่เลือก (มี ->result เป็น array จาก DcaPlanService)
    $r = $plan->result ?? null;
    $assets = $r['assets'] ?? [];
    $totals = $r['totals'] ?? [];
    $meta   = $r['meta'] ?? [];
    $profitTotal = $totals['profit_thb'] ?? 0;
    $up = $profitTotal >= 0;
@endphp

@if(empty($assets))
    <div class="glass-card p-6 border border-amber-200 bg-amber-50">
        <p class="text-amber-700 text-sm">
            พอร์ต <strong>{{ $plan->portfolio->name ?? '-' }}</strong> ยังไม่มีสินทรัพย์คงเหลือ —
            เพิ่มหุ้น/กองทุน/ทองในพอร์ตก่อน แล้วแก้ไขแผนนี้เพื่อคำนวณใหม่
        </p>
    </div>
@else
    {{-- สรุปหัวแผน --}}
    <div class="glass-card p-5 mb-4">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
            <div>
                <h2 class="font-bold text-slate-900 text-lg">{{ $plan->name }}</h2>
                <p class="text-xs text-slate-500 mt-0.5">
                    พอร์ต: {{ $plan->portfolio->name ?? '-' }} ·
                    DCA {{ $meta['frequency_label'] ?? $plan->frequency }} ·
                    {{ $plan->years }} ปี
                    @if($meta['start_date'] ?? false)
                        · เริ่ม {{ \Illuminate\Support\Carbon::parse($meta['start_date'])->format('d/m/Y') }}
                        @if($meta['end_date'] ?? false) → {{ \Illuminate\Support\Carbon::parse($meta['end_date'])->format('d/m/Y') }} @endif
                    @endif
                    @if($plan->current_age && $plan->retire_age)
                        · อายุ {{ $plan->current_age }} → เกษียณ {{ $plan->retire_age }}
                    @endif
                </p>
            </div>
            <span class="text-xs text-slate-400">คำนวณ: {{ $plan->computed_at?->format('d/m/Y H:i') }}</span>
        </div>

        {{-- การ์ดตัวเลขรวม --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
            <div class="rounded-xl bg-slate-50 p-3">
                <p class="text-xs text-slate-500">ทุนรวม</p>
                <p class="text-lg font-bold text-slate-800 mt-1">{{ number_format($totals['invested_thb'] ?? 0, 0) }}</p>
                <p class="text-[11px] text-slate-400">ตั้งต้น {{ number_format($totals['start_value_thb'] ?? 0, 0) }} + DCA {{ number_format($totals['contrib_thb'] ?? 0, 0) }}</p>
            </div>
            <div class="rounded-xl bg-indigo-50 p-3">
                <p class="text-xs text-indigo-600">มูลค่าคาดการณ์</p>
                <p class="text-lg font-bold text-indigo-700 mt-1">{{ number_format($totals['future_value_thb'] ?? 0, 0) }}</p>
                <p class="text-[11px] text-indigo-400">บาท ในอีก {{ $plan->years }} ปี</p>
            </div>
            <div class="rounded-xl {{ $up ? 'bg-emerald-50' : 'bg-rose-50' }} p-3">
                <p class="text-xs {{ $up ? 'text-emerald-600' : 'text-rose-600' }}">กำไร/ขาดทุน</p>
                <p class="text-lg font-bold {{ $up ? 'text-emerald-700' : 'text-rose-700' }} mt-1">
                    {{ $up ? '+' : '' }}{{ number_format($profitTotal, 0) }}
                </p>
                <p class="text-[11px] {{ $up ? 'text-emerald-400' : 'text-rose-400' }}">บาท</p>
            </div>
            <div class="rounded-xl {{ $up ? 'bg-emerald-50' : 'bg-rose-50' }} p-3">
                <p class="text-xs {{ $up ? 'text-emerald-600' : 'text-rose-600' }}">ผลตอบแทน</p>
                <p class="text-lg font-bold {{ $up ? 'text-emerald-700' : 'text-rose-700' }} mt-1">
                    {{ $up ? '+' : '' }}{{ number_format($totals['profit_pct'] ?? 0, 1) }}%
                </p>
                <p class="text-[11px] {{ $up ? 'text-emerald-400' : 'text-rose-400' }}">ตลอดแผน</p>
            </div>
        </div>
    </div>

    {{-- ตารางรายสินทรัพย์ --}}
    <div class="glass-card p-5 mb-4 overflow-x-auto">
        <h3 class="font-semibold text-slate-800 mb-3 text-sm">รายละเอียดรายสินทรัพย์</h3>
        <table class="w-full text-sm min-w-[680px]">
            <thead>
                <tr class="text-xs text-slate-400 border-b border-slate-100">
                    <th class="text-left font-medium pb-2">สินทรัพย์</th>
                    <th class="text-right font-medium pb-2">ตั้งต้น</th>
                    <th class="text-right font-medium pb-2">DCA/ครั้ง</th>
                    <th class="text-right font-medium pb-2">CAGR/ปี</th>
                    <th class="text-right font-medium pb-2">ทุนรวม</th>
                    <th class="text-right font-medium pb-2">คาดการณ์</th>
                    <th class="text-right font-medium pb-2">กำไร %</th>
                </tr>
            </thead>
            <tbody>
                @foreach($assets as $a)
                    @php $au = ($a['profit_pct'] ?? 0) >= 0; @endphp
                    <tr class="border-b border-slate-50">
                        <td class="py-2.5">
                            <div class="font-medium text-slate-800">{{ $a['symbol'] }}</div>
                            <div class="text-xs text-slate-400 truncate max-w-[160px]">{{ $a['name'] }}</div>
                        </td>
                        <td class="text-right text-slate-600">{{ number_format($a['start_value_thb'], 0) }}</td>
                        <td class="text-right text-slate-600">{{ $a['dca_amount'] > 0 ? number_format($a['dca_amount'], 0) : '—' }}</td>
                        <td class="text-right text-slate-600">
                            {{ number_format($a['cagr_pct'], 1) }}%
                            @if($a['cagr_custom'] ?? false)
                                <span class="text-indigo-500" title="ปรับเอง">✎</span>
                            @elseif($a['cagr_estimated'])
                                <span class="text-amber-500" title="ข้อมูลไม่พอ — ใช้ค่าเฉลี่ยพอร์ต">≈</span>
                            @endif
                        </td>
                        <td class="text-right text-slate-600">{{ number_format($a['invested_thb'], 0) }}</td>
                        <td class="text-right font-medium text-indigo-700">{{ number_format($a['future_value_thb'], 0) }}</td>
                        <td class="text-right font-medium {{ $au ? 'text-emerald-600' : 'text-rose-600' }}">
                            {{ $au ? '+' : '' }}{{ number_format($a['profit_pct'], 1) }}%
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="text-[11px] text-slate-400 mt-3">
            ⚠️ เป็นการ <strong>ประมาณการ</strong> ไม่ใช่การรับประกัน · CAGR อัตโนมัติ cap ที่ {{ (int) round(\App\Services\DcaPlanService::CAGR_MAX * 100) }}%/ปี (กันการ extrapolate ผลตอบแทนช่วงบูมเกินจริง)
            · <span class="text-indigo-500">✎</span> = ปรับเอง · <span class="text-amber-500">≈</span> = ข้อมูลไม่พอ ใช้ค่าเฉลี่ยพอร์ต
        </p>
    </div>
@endif
