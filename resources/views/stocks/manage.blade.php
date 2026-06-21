@extends('layouts.app')

@section('title', 'จัดการหุ้น — Stock AI')

@section('content')

<div class="mb-8">
    <h1 class="text-2xl font-bold text-slate-900">จัดการหุ้น</h1>
    <p class="text-slate-500 text-sm mt-1">เพิ่มหุ้นที่สนใจ ระบบจะดึงข้อมูลราคาย้อนหลังจาก Yahoo Finance อัตโนมัติ</p>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    {{-- Add Stock Form --}}
    <div class="glass-card p-6">
        <h2 class="font-semibold text-slate-800 mb-4 flex items-center gap-2">
            <span class="w-6 h-6 bg-indigo-100 text-indigo-600 rounded-md flex items-center justify-center text-xs font-bold">+</span>
            เพิ่มหุ้นใหม่
        </h2>

        <form method="POST" action="{{ route('manage.store') }}" class="space-y-4">
            @csrf
            <div>
                <label class="block text-sm font-medium text-slate-600 mb-1.5">Symbol หุ้น</label>
                <input type="text" name="symbol" placeholder="เช่น AAPL, PTT.BK, NVDA"
                    value="{{ old('symbol') }}"
                    class="w-full border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm text-slate-800 placeholder-slate-400
                           focus:outline-none focus:ring-2 focus:ring-indigo-400 focus:border-transparent transition bg-white/70">
                @error('symbol') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                <p class="text-xs text-slate-400 mt-1.5">หุ้นไทยใช้ suffix .BK เช่น <strong>PTT.BK</strong> · US ใช้ symbol ตรงๆ เช่น <strong>AAPL</strong></p>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-600 mb-1.5">ดึงข้อมูลย้อนหลัง</label>
                <select name="years" class="w-full border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm text-slate-800
                                            focus:outline-none focus:ring-2 focus:ring-indigo-400 focus:border-transparent bg-white/70">
                    @foreach([1 => '1 ปี', 3 => '3 ปี', 5 => '5 ปี (แนะนำ)', 10 => '10 ปี', 15 => '15 ปี', 20 => '20 ปี'] as $y => $label)
                        <option value="{{ $y }}" {{ old('years', 5) == $y ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit"
                class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2.5 rounded-xl text-sm
                       transition-all shadow-sm hover:shadow-indigo-200 hover:shadow-lg active:scale-[0.98]">
                ดึงข้อมูลและเพิ่ม
            </button>
        </form>

        <div class="mt-5 pt-5 border-t border-slate-100">
            <p class="text-xs font-medium text-slate-500 mb-3">หุ้นยอดนิยม</p>
            <div class="flex flex-wrap gap-2">
                @foreach(['AAPL','NVDA','MSFT','TSLA','GOOGL','PTT.BK','ADVANC.BK','CPALL.BK','SCB.BK','AOT.BK'] as $s)
                <button onclick="document.querySelector('[name=symbol]').value='{{ $s }}'"
                    class="px-2.5 py-1 bg-slate-100 hover:bg-indigo-50 hover:text-indigo-600 text-slate-600 rounded-lg text-xs font-medium transition cursor-pointer border border-transparent hover:border-indigo-200">
                    {{ $s }}
                </button>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Stock List --}}
    <div class="lg:col-span-2 glass-card p-6">
        <h2 class="font-semibold text-slate-800 mb-4">หุ้นในระบบ ({{ $stocks->count() }} ตัว)</h2>

        @if($stocks->isEmpty())
            <div class="text-center py-16">
                <div class="text-5xl mb-3">📭</div>
                <p class="text-slate-400 text-sm">ยังไม่มีหุ้น — เพิ่มหุ้นแรกจากแบบฟอร์มด้านซ้าย</p>
            </div>
        @else
            <div class="space-y-2">
                @foreach($stocks as $stock)
                <div class="flex items-center justify-between p-3.5 bg-white/60 border border-slate-100 rounded-xl hover:border-indigo-200 hover:bg-indigo-50/30 transition group">
                    <div class="flex items-center gap-3">
                        <div class="w-9 h-9 bg-gradient-to-br from-slate-100 to-slate-200 rounded-lg flex items-center justify-center">
                            <span class="text-xs font-bold text-slate-500">{{ substr($stock->symbol, 0, 2) }}</span>
                        </div>
                        <div>
                            <a href="{{ route('stocks.show', $stock) }}" class="font-semibold text-slate-800 hover:text-indigo-600 transition text-sm">{{ $stock->symbol }}</a>
                            <div class="text-xs text-slate-400">{{ $stock->name ?? '—' }} · {{ $stock->exchange ?? '—' }}</div>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        {{-- Refresh --}}
                        <form method="POST" action="{{ route('manage.refresh', $stock) }}" class="inline">
                            @csrf
                            <input type="hidden" name="years" value="1">
                            <button type="submit" title="อัปเดตข้อมูล"
                                class="p-2 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                                </svg>
                            </button>
                        </form>

                        {{-- Analyze --}}
                        <a href="{{ route('stocks.analyze', $stock) }}"
                           class="p-2 text-slate-400 hover:text-purple-600 hover:bg-purple-50 rounded-lg transition" title="AI วิเคราะห์">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z"/>
                            </svg>
                        </a>

                        {{-- Delete --}}
                        <form method="POST" action="{{ route('manage.destroy', $stock) }}" class="inline"
                              onsubmit="return confirm('ลบ {{ $stock->symbol }} ออกจากระบบ? ข้อมูลราคาทั้งหมดจะถูกลบด้วย')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" title="ลบ"
                                class="p-2 text-slate-300 hover:text-red-500 hover:bg-red-50 rounded-lg transition">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                </svg>
                            </button>
                        </form>
                    </div>
                </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
@endsection
