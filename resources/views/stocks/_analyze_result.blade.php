{{-- Partial: ผลวิเคราะห์ AI — ใช้ render ทั้งตอนโหลดหน้าและตอบกลับ AJAX --}}
@if(!$result['success'])
    <div class="glass-card p-6 border border-red-200 bg-red-50">
        <p class="text-red-600 text-sm">{{ $result['error'] }}</p>
    </div>
@else
    {{-- AI Summary banner --}}
    <div class="glass-card p-5 bg-gradient-to-r from-indigo-50 to-purple-50 border-indigo-100 mb-5">
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
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-5">
        @foreach([
            'bull' => ['🚀', 'Bull', 'from-emerald-50 to-green-50', 'border-emerald-200', 'text-emerald-700'],
            'base' => ['📊', 'Base', 'from-indigo-50 to-blue-50', 'border-indigo-200', 'text-indigo-700'],
            'bear' => ['🐻', 'Bear', 'from-red-50 to-rose-50', 'border-red-200', 'text-red-600'],
        ] as $case => [$icon, $label, $bg, $border, $textColor])
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
    <div class="glass-card p-6">
        <h3 class="font-semibold text-slate-800 mb-4">
            กราฟการเติบโต {{ $result['years'] }} ปี
            <span class="text-xs text-slate-400 font-normal ml-1">({{ $result['currency'] }})</span>
        </h3>
        <canvas id="projectionChart" height="90"></canvas>
    </div>
@endif
