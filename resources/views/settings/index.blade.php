@extends('layouts.app')

@section('title', 'ตั้งค่าระบบ — Invest AI')

@section('content')

<div class="mb-8">
    <h1 class="text-2xl font-bold text-slate-900">⚙️ ตั้งค่าระบบ</h1>
    <p class="text-slate-500 text-sm mt-1">จัดการ API Key, ตารางเวลาส่งสรุป และค่าต่างๆ — ค่า secret ถูกเข้ารหัสก่อนเก็บลงฐานข้อมูล</p>
</div>

<form method="POST" action="{{ route('settings.update') }}">
    @csrf

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    @foreach($groups as $groupKey => $items)
    <div class="glass-card p-6 self-start">
        <h2 class="font-semibold text-slate-800 mb-5 flex items-center gap-2">
            <span>{{ $groupLabels[$groupKey]['icon'] ?? '•' }}</span>
            {{ $groupLabels[$groupKey]['title'] ?? $groupKey }}
        </h2>

        <div class="space-y-4">
            @foreach($items as $key => $item)
                @php
                    $meta   = $item['meta'];
                    $field  = str_replace('.', '__', $key);
                    $secret = $meta['secret'] ?? false;
                @endphp
                <div>
                    <label class="flex items-center gap-2 text-sm font-medium text-slate-600 mb-1.5">
                        {{ $meta['label'] }}
                        @if($secret)
                            @if($item['is_set'])
                                <span class="text-xs bg-emerald-100 text-emerald-700 px-2 py-0.5 rounded-full">ตั้งค่าแล้ว ••••</span>
                            @else
                                <span class="text-xs bg-amber-100 text-amber-700 px-2 py-0.5 rounded-full">ยังไม่ตั้งค่า</span>
                            @endif
                        @endif
                    </label>

                    @if(($meta['type'] ?? '') === 'select' && !empty($meta['options']))
                        <select name="{{ $field }}"
                            class="w-full border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm text-slate-800
                                   focus:outline-none focus:ring-2 focus:ring-indigo-400 focus:border-transparent transition bg-white/70">
                            @foreach($meta['options'] as $optVal => $optLabel)
                                <option value="{{ $optVal }}" @selected($item['value'] === $optVal || (!$item['value'] && ($meta['default'] ?? '') === $optVal))>
                                    {{ $optLabel }}
                                </option>
                            @endforeach
                        </select>
                    @else
                        <input
                            type="{{ $secret ? 'password' : 'text' }}"
                            name="{{ $field }}"
                            value="{{ $secret ? '' : $item['value'] }}"
                            placeholder="{{ $secret ? ($item['is_set'] ? 'เว้นว่างไว้ = ไม่เปลี่ยนค่าเดิม' : 'กรอกค่า...') : '' }}"
                            autocomplete="{{ $secret ? 'new-password' : 'off' }}"
                            class="w-full border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm text-slate-800 placeholder-slate-400
                                   focus:outline-none focus:ring-2 focus:ring-indigo-400 focus:border-transparent transition bg-white/70">
                    @endif

                    @if(!empty($meta['help']))
                        <p class="text-xs text-slate-400 mt-1.5">{{ $meta['help'] }}</p>
                    @endif
                </div>
            @endforeach

            {{-- Webhook URL (เฉพาะการ์ด LINE) — สำหรับนำไปตั้งใน LINE Developers Console --}}
            @if($groupKey === 'line')
                <div class="pt-2 border-t border-slate-100">
                    <label class="block text-sm font-medium text-slate-600 mb-1.5">Webhook URL</label>
                    <div class="flex gap-2">
                        <input id="webhookUrl" type="text" readonly value="{{ $webhookUrl }}"
                            class="flex-1 border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm text-slate-700 bg-slate-50 font-mono select-all">
                        <button type="button" id="copyWebhookBtn" data-copy-target="#webhookUrl"
                            class="shrink-0 px-4 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-xl transition active:scale-[0.97] flex items-center gap-1.5">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                            </svg>
                            คัดลอก
                        </button>
                    </div>
                    <p class="text-xs text-slate-400 mt-1.5">นำไปวางใน LINE Developers Console → Messaging API → Webhook URL แล้วกด Verify + เปิด Use webhook</p>
                </div>
            @endif
        </div>
    </div>
    @endforeach
    </div>

    <div class="flex items-center gap-3 mt-6">
        <button type="submit"
            class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium px-6 py-2.5 rounded-xl text-sm transition-all shadow-sm hover:shadow-indigo-200 hover:shadow-lg active:scale-[0.98]">
            บันทึกการตั้งค่า
        </button>
        <p class="text-xs text-slate-400">การเปลี่ยนแปลงมีผลทันที (cache ถูกล้างอัตโนมัติ)</p>
    </div>
</form>

@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const btn = document.getElementById('copyWebhookBtn');
    if (!btn) return;
    btn.addEventListener('click', async function () {
        const input = document.querySelector(btn.dataset.copyTarget);
        try {
            await navigator.clipboard.writeText(input.value);
        } catch (e) {
            // fallback สำหรับ browser เก่า / ไม่ใช่ https
            input.select();
            document.execCommand('copy');
        }
        window.toast('success', 'คัดลอก Webhook URL แล้ว');
    });
});
</script>
@endpush
