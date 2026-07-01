@extends('layouts.app')

@section('title', 'แผน DCA — InvestAI')

@section('content')

<div class="flex flex-wrap items-start justify-between gap-3 mb-6">
    <div>
        <h1 class="text-2xl font-bold text-slate-900">📅 แผน DCA</h1>
        <p class="text-slate-500 text-sm mt-1">ดึงสินทรัพย์จากพอร์ต → กำหนดยอด DCA รายตัว → คำนวณ projection จาก CAGR ย้อนหลัง + AI ตีความ</p>
    </div>
    @if($selected)
        <a href="{{ route('plan.index') }}" class="inline-flex items-center gap-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium px-4 py-2.5 rounded-xl transition shadow-sm">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
            แผนใหม่
        </a>
    @endif
</div>

{{-- รายการแผนที่บันทึกไว้ --}}
@if($plans->isNotEmpty())
<div class="flex flex-wrap gap-2 mb-5">
    @foreach($plans as $p)
        @php $isSelected = $selected && $selected->id === $p->id; @endphp
        <div class="inline-flex items-stretch rounded-xl border overflow-hidden transition
                    {{ $isSelected ? 'border-indigo-200' : 'border-slate-200' }}">
            <a href="{{ route('plan.index', ['plan' => $p->id]) }}"
               class="inline-flex items-center gap-2 px-3.5 py-2 text-sm transition
                      {{ $isSelected ? 'bg-indigo-50 text-indigo-700 font-medium' : 'bg-white/60 text-slate-600 hover:bg-slate-50' }}">
                {{ $p->name }}
                <span class="text-xs {{ $isSelected ? 'text-indigo-400' : 'text-slate-400' }}">{{ $p->years }} ปี</span>
            </a>
            <form method="POST" action="{{ route('plan.destroy', $p) }}" class="confirm-delete flex"
                  data-title="ลบแผน {{ $p->name }}?" data-message="ลบทั้งแผนและสินทรัพย์ในแผน — กู้คืนไม่ได้">
                @csrf @method('DELETE')
                <button type="submit" title="ลบแผนนี้"
                    class="px-2 flex items-center text-slate-400 hover:text-red-500 hover:bg-red-50 border-l {{ $isSelected ? 'border-indigo-200' : 'border-slate-200' }} transition">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                </button>
            </form>
        </div>
    @endforeach
</div>
@endif

{{-- ─── ผลแผนที่เลือก ─── --}}
@if($selected)
    @include('plan._result', ['plan' => $selected])

    {{-- AI ตีความ --}}
    @php $hasPositions = $selected->result['meta']['has_positions'] ?? false; @endphp
    <div class="glass-card p-5 mb-6">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
            <h3 class="font-semibold text-slate-800 text-sm">🤖 AI วิเคราะห์แผน</h3>
            <div class="flex items-center gap-2">
                <span id="aiTime" class="text-xs text-slate-400">{{ $selected->ai_analysis ? 'ล่าสุด: ' . $selected->updated_at->format('d/m/Y H:i') : '' }}</span>
                @if($hasPositions)
                    <button type="button" id="analyzeBtn" data-url="{{ route('plan.analyze', $selected) }}"
                        class="inline-flex items-center gap-1.5 bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-700 hover:to-purple-700 text-white text-xs font-semibold px-3.5 py-2 rounded-lg transition shadow-sm disabled:opacity-60 disabled:cursor-not-allowed">
                        <span class="ai-btn-label">{{ $selected->ai_analysis ? '🔄 วิเคราะห์ใหม่' : '🤖 วิเคราะห์ด้วย AI' }}</span>
                    </button>
                @endif
            </div>
        </div>

        <div id="aiLoading" class="hidden flex-col items-center justify-center py-12 text-center">
            <div class="relative w-12 h-12 mb-4">
                <div class="absolute inset-0 rounded-full border-4 border-indigo-100"></div>
                <div class="absolute inset-0 rounded-full border-4 border-indigo-500 border-t-transparent animate-spin"></div>
                <div class="absolute inset-0 flex items-center justify-center text-xl">🤖</div>
            </div>
            <p class="text-slate-600 text-sm font-medium">AI กำลังวิเคราะห์แผน...</p>
        </div>

        <div id="aiResult">
            @if($aiHtml)
                <div class="md-content text-sm text-slate-600">{!! $aiHtml !!}</div>
            @else
                <div class="text-center py-8">
                    <p class="text-slate-400 text-sm">
                        {{ $hasPositions ? 'ยังไม่มีบทวิเคราะห์ — กดปุ่ม "วิเคราะห์ด้วย AI" ด้านบน' : 'เพิ่มสินทรัพย์ในพอร์ตก่อน' }}
                    </p>
                </div>
            @endif
        </div>
    </div>
@endif

{{-- ─── ฟอร์มสร้าง / แก้แผน ─── --}}
<div class="glass-card p-5 sm:p-6">
    @if($selected)
        <div class="flex items-center justify-between mb-5">
            <h2 class="font-semibold text-slate-800">✏️ แก้ไขแผน "{{ $selected->name }}"</h2>
            <form method="POST" action="{{ route('plan.destroy', $selected) }}"
                  class="confirm-delete" data-title="ลบแผน {{ $selected->name }}?" data-message="ลบแล้วกู้คืนไม่ได้">
                @csrf @method('DELETE')
                <button type="submit" class="inline-flex items-center gap-1.5 text-sm text-slate-400 hover:text-red-500 hover:bg-red-50 px-3 py-2 rounded-xl transition">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                    ลบแผน
                </button>
            </form>
        </div>
        <form method="POST" action="{{ route('plan.update', $selected) }}">
            @csrf @method('PUT')
            @include('plan._form', ['plan' => $selected])
            <button type="submit" class="w-full sm:w-auto mt-5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold px-8 py-2.5 rounded-xl transition shadow-sm">
                บันทึก & คำนวณใหม่
            </button>
        </form>
    @else
        <h2 class="font-semibold text-slate-800 mb-5">➕ สร้างแผนใหม่</h2>
        <form method="POST" action="{{ route('plan.store') }}">
            @csrf
            @include('plan._form', ['plan' => null])
            <button type="submit" class="w-full sm:w-auto mt-5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold px-8 py-2.5 rounded-xl transition shadow-sm">
                สร้างแผนและคำนวณ
            </button>
        </form>
    @endif
</div>

@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    // เปลี่ยนพอร์ต → reload เพื่อดึงสินทรัพย์ของพอร์ตนั้น
    const pfSelect = document.querySelector('[data-portfolio-reload]');
    if (pfSelect) {
        pfSelect.addEventListener('change', function () {
            const planId = this.dataset.planId;
            const params = new URLSearchParams();
            if (planId) params.set('plan', planId);
            params.set('portfolio', this.value);
            location.href = '{{ route('plan.index') }}?' + params.toString();
        });
    }

    // ความถี่ custom → โชว์ช่องวันที่ของเดือน
    const box = document.getElementById('customDaysBox');
    document.querySelectorAll('[data-freq]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            if (!box) return;
            box.classList.toggle('hidden', this.value !== 'custom');
        });
    });

    // ปุ่มลบ/เพิ่มกลับ สินทรัพย์ในแผน (toggle สถานะ excluded ในที่เดิม)
    document.querySelectorAll('[data-asset-row]').forEach(function (row) {
        const btn = row.querySelector('[data-exclude-toggle]');
        const cb  = row.querySelector('.exclude-cb');
        if (!btn || !cb) return;
        btn.addEventListener('click', function () {
            const excluded = !cb.checked;
            cb.checked = excluded;
            row.classList.toggle('opacity-50', excluded);
            row.querySelector('.font-medium')?.classList.toggle('line-through', excluded);
            row.querySelectorAll('.asset-input').forEach(i => i.disabled = excluded);
            row.querySelector('[data-icon-remove]')?.classList.toggle('hidden', excluded);
            row.querySelector('[data-icon-restore]')?.classList.toggle('hidden', !excluded);
        });
    });

    // ปุ่ม AI วิเคราะห์ (ปุ่มถาวร — กดวิเคราะห์ใหม่ได้ไม่จำกัด)
    const btn = document.getElementById('analyzeBtn');
    if (btn) {
        const loading = document.getElementById('aiLoading');
        const result  = document.getElementById('aiResult');
        const timeEl  = document.getElementById('aiTime');
        const label   = btn.querySelector('.ai-btn-label');

        btn.addEventListener('click', async function () {
            btn.disabled = true;
            loading.classList.remove('hidden'); loading.classList.add('flex');
            result.classList.add('hidden');
            try {
                const res = await fetch(btn.dataset.url, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        'Accept': 'application/json',
                    },
                });
                const data = await res.json();
                if (data.success) {
                    result.innerHTML = `<div class="md-content text-sm text-slate-600">${data.analysis_html}</div>`;
                    if (label) label.textContent = '🔄 วิเคราะห์ใหม่';
                    if (timeEl && data.analyzed_at) timeEl.textContent = 'ล่าสุด: ' + data.analyzed_at;
                } else {
                    result.innerHTML = `<p class="text-rose-500 text-sm">${data.message ?? 'AI วิเคราะห์ไม่สำเร็จ'}</p>`;
                }
            } catch (err) {
                result.innerHTML = `<p class="text-rose-500 text-sm">เกิดข้อผิดพลาด — กรุณาลองใหม่</p>`;
            } finally {
                loading.classList.add('hidden'); loading.classList.remove('flex');
                result.classList.remove('hidden');
                btn.disabled = false;
            }
        });
    }
});
</script>
@endpush
