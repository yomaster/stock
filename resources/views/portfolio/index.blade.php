@extends('layouts.app')

@section('title', 'พอร์ตการลงทุน — Stock AI')

@section('content')

<div class="flex flex-wrap items-start justify-between gap-4 mb-8">
    <div>
        <h1 class="text-2xl font-bold text-slate-900">💼 พอร์ตการลงทุน</h1>
        <p class="text-slate-500 text-sm mt-1">ราคาหุ้นสดจาก Yahoo (~15 นาที) · เรท USD→THB ≈ {{ number_format($rate, 2) }} บาท · กำไร/ขาดทุนคิดทั้งราคาหุ้นและค่าเงิน</p>
    </div>

    {{-- ตัวเลือกพอร์ต --}}
    <div class="flex items-center gap-2">
        <select id="portfolioSwitch" onchange="location.href=this.value"
            class="border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm font-medium text-slate-700 bg-white/70 focus:outline-none focus:ring-2 focus:ring-indigo-400 max-w-48">
            @foreach($portfolios as $p)
                <option value="{{ route('portfolio.portfolios.switch', $p) }}" {{ $p->id === $portfolio->id ? 'selected' : '' }}>
                    {{ $p->name }}
                </option>
            @endforeach
        </select>

        <button type="button" id="renamePortfolioBtn" title="แก้ไขชื่อพอร์ต" data-name="{{ $portfolio->name }}"
            class="p-2.5 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 border border-slate-200 rounded-xl transition">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
        </button>

        <button type="button" id="newPortfolioBtn" title="สร้างพอร์ตใหม่"
            class="p-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl transition shadow-sm">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
        </button>

        @if($portfolios->count() > 1)
        <form method="POST" action="{{ route('portfolio.portfolios.destroy', $portfolio) }}" class="confirm-delete"
              data-title="ลบพอร์ต {{ $portfolio->name }}?" data-message="หุ้นทั้งหมดในพอร์ตนี้จะถูกลบด้วย">
            @csrf @method('DELETE')
            <button type="submit" title="ลบพอร์ตนี้"
                class="p-2.5 text-slate-400 hover:text-red-500 hover:bg-red-50 border border-slate-200 rounded-xl transition">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
            </button>
        </form>
        @endif

        {{-- ฟอร์มซ่อนสำหรับสร้างพอร์ต (เรียกผ่าน SweetAlert) --}}
        <form id="newPortfolioForm" method="POST" action="{{ route('portfolio.portfolios.store') }}" class="hidden">
            @csrf
            <input type="hidden" name="name" id="newPortfolioName">
        </form>
        {{-- ฟอร์มซ่อนสำหรับเปลี่ยนชื่อพอร์ต --}}
        <form id="renamePortfolioForm" method="POST" action="{{ route('portfolio.portfolios.rename', $portfolio) }}" class="hidden">
            @csrf @method('PUT')
            <input type="hidden" name="name" id="renamePortfolioName">
        </form>
    </div>
</div>

{{-- สรุปรวม --}}
@if(!empty($transactions))
@php $isProfit = $total_unrealized_pl >= 0; $rzProfit = $total_realized_pl >= 0; @endphp
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <div class="glass-card p-4 text-center">
        <div class="text-xs text-slate-500 mb-1">มูลค่าพอร์ต</div>
        <div class="text-xl font-bold text-slate-800">{{ number_format($total_value_thb, 0) }}</div>
        <div class="text-xs text-slate-400">บาท</div>
    </div>
    <div class="glass-card p-4 text-center">
        <div class="text-xs text-slate-500 mb-1">ต้นทุนคงเหลือ</div>
        <div class="text-xl font-bold text-slate-800">{{ number_format($total_cost_thb, 0) }}</div>
        <div class="text-xs text-slate-400">บาท</div>
    </div>
    <div class="glass-card p-4 text-center {{ $isProfit ? 'bg-emerald-50' : 'bg-red-50' }}">
        <div class="text-xs {{ $isProfit ? 'text-emerald-600' : 'text-red-600' }} mb-1">กำไร/ขาดทุน (ยังถือ)</div>
        <div class="text-xl font-bold {{ $isProfit ? 'text-emerald-700' : 'text-red-700' }}">{{ $isProfit ? '+' : '' }}{{ number_format($total_unrealized_pl, 0) }}</div>
        <div class="text-xs {{ $isProfit ? 'text-emerald-500' : 'text-red-500' }}">{{ $isProfit ? '+' : '' }}{{ number_format($total_unrealized_pct, 1) }}%</div>
    </div>
    <div class="glass-card p-4 text-center {{ $rzProfit ? 'bg-emerald-50' : 'bg-red-50' }}">
        <div class="text-xs {{ $rzProfit ? 'text-emerald-600' : 'text-red-600' }} mb-1">กำไรรับรู้แล้ว (ขายไป)</div>
        <div class="text-xl font-bold {{ $rzProfit ? 'text-emerald-700' : 'text-red-700' }}">{{ $rzProfit ? '+' : '' }}{{ number_format($total_realized_pl, 0) }}</div>
        <div class="text-xs text-slate-400">บาท</div>
    </div>
</div>
@endif

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    {{-- ฟอร์มเพิ่มหุ้น --}}
    <div class="glass-card p-6 self-start">
        <h2 class="font-semibold text-slate-800 mb-4">เพิ่มหุ้นเข้าพอร์ต</h2>

        {{-- ทางลัด: นำเข้าจากภาพ (ใช้ก่อนกรอกมือ) --}}
        <button type="button" id="importImageBtn"
            class="w-full border border-indigo-200 text-indigo-600 hover:bg-indigo-50 font-medium py-2.5 rounded-xl text-sm transition flex items-center justify-center gap-2 active:scale-[0.98]">
            📷 นำเข้าจากภาพหน้าจอโบรก
        </button>
        <p class="text-xs text-slate-400 mt-1.5 text-center">อัปโหลดภาพรายการซื้อ-ขาย (Dime ฯลฯ) ได้หลายภาพ — AI อ่านให้อัตโนมัติ</p>

        <div class="flex items-center gap-3 my-4">
            <div class="flex-1 border-t border-slate-100"></div>
            <span class="text-xs text-slate-400">หรือกรอกเอง</span>
            <div class="flex-1 border-t border-slate-100"></div>
        </div>

        <form method="POST" action="{{ route('portfolio.items.store') }}" class="space-y-4" id="addItemForm">
            @csrf
            <input type="hidden" name="type" id="itemType" value="buy">

            {{-- ซื้อ / ขาย --}}
            <div class="grid grid-cols-2 gap-2">
                <button type="button" data-type="buy" class="type-btn border border-slate-200 rounded-xl py-2.5 text-sm font-medium text-slate-600 transition">🟢 ซื้อ (เพิ่ม)</button>
                <button type="button" data-type="sell" class="type-btn border border-slate-200 rounded-xl py-2.5 text-sm font-medium text-slate-600 transition">🔴 ขาย (หักออก)</button>
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-600 mb-1.5">หุ้น</label>
                <select name="stock_id" required class="w-full border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm bg-white/70 focus:outline-none focus:ring-2 focus:ring-indigo-400">
                    <option value="">— เลือกหุ้น —</option>
                    @foreach($stocks as $s)
                        <option value="{{ $s->id }}">{{ $s->symbol }} — {{ $s->name }}</option>
                    @endforeach
                </select>
                @error('stock_id') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            {{-- BUY fields (ซ่อนเมื่อเลือกขาย) --}}
            <div id="buyFields" class="space-y-4">
            {{-- เลือกวิธีเพิ่ม --}}
            <div>
                <label class="block text-sm font-medium text-slate-600 mb-1.5">วิธีเพิ่ม</label>
                <div class="grid grid-cols-2 gap-2">
                    <label class="cursor-pointer">
                        <input type="radio" name="mode" value="amount" checked class="peer sr-only" onchange="switchMode('amount')">
                        <div class="border border-slate-200 peer-checked:border-indigo-500 peer-checked:bg-indigo-50 rounded-xl py-2.5 text-center text-xs font-medium text-slate-600 peer-checked:text-indigo-700 transition">
                            💵 ตามจำนวนเงิน
                        </div>
                    </label>
                    <label class="cursor-pointer">
                        <input type="radio" name="mode" value="shares" class="peer sr-only" onchange="switchMode('shares')">
                        <div class="border border-slate-200 peer-checked:border-indigo-500 peer-checked:bg-indigo-50 rounded-xl py-2.5 text-center text-xs font-medium text-slate-600 peer-checked:text-indigo-700 transition">
                            📊 ตามจำนวนหุ้น
                        </div>
                    </label>
                </div>
            </div>

            {{-- โหมดจำนวนเงิน --}}
            <div id="mode-amount" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-slate-600 mb-1.5">จำนวนเงินที่ลงทุน</label>
                    <input type="number" name="invested_amount" step="any" min="1" placeholder="เช่น 5000"
                        class="w-full border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm bg-white/70 focus:outline-none focus:ring-2 focus:ring-indigo-400">
                    @error('invested_amount') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-600 mb-1.5">สกุลเงินที่จ่าย</label>
                    <div class="grid grid-cols-2 gap-2">
                        @foreach(['THB' => '🇹🇭 บาท', 'USD' => '🇺🇸 ดอลลาร์'] as $code => $label)
                        <label class="cursor-pointer">
                            <input type="radio" name="invested_currency" value="{{ $code }}" {{ $code === 'THB' ? 'checked' : '' }} class="peer sr-only">
                            <div class="border border-slate-200 peer-checked:border-indigo-500 peer-checked:bg-indigo-50 rounded-xl py-2.5 text-center text-sm font-medium text-slate-600 peer-checked:text-indigo-700 transition">
                                {{ $label }}
                            </div>
                        </label>
                        @endforeach
                    </div>
                    <p class="text-xs text-slate-400 mt-1">Dime ซื้อหุ้น US ด้วยเงินบาท → เลือกบาท</p>
                </div>
            </div>

            {{-- โหมดจำนวนหุ้น (รวบรัด) --}}
            <div id="mode-shares" class="space-y-4 hidden">
                <div>
                    <label class="block text-sm font-medium text-slate-600 mb-1.5">จำนวนหุ้นที่ถืออยู่</label>
                    <input type="number" name="shares" step="any" min="0.0001" placeholder="เช่น 0.5 (เศษหุ้นได้)" disabled
                        class="w-full border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm bg-white/70 focus:outline-none focus:ring-2 focus:ring-indigo-400">
                    @error('shares') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-600 mb-1.5">ต้นทุนเฉลี่ย/หุ้น <span class="text-slate-400 font-normal">(ไม่บังคับ)</span></label>
                    <input type="number" name="avg_cost" step="any" min="0" placeholder="เว้นว่าง = ใช้ราคาปัจจุบันเป็นฐาน" disabled
                        class="w-full border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm bg-white/70 focus:outline-none focus:ring-2 focus:ring-indigo-400">
                    <p class="text-xs text-slate-400 mt-1">สกุลของหุ้น (ไทย=บาท, US=ดอลลาร์) · ถ้าไม่รู้ปล่อยว่างได้</p>
                </div>
            </div>
            </div>{{-- /#buyFields --}}

            {{-- SELL fields (ซ่อนเมื่อเลือกซื้อ) --}}
            <div id="sellFields" class="space-y-4 hidden">
                <div>
                    <label class="block text-sm font-medium text-slate-600 mb-1.5">จำนวนหุ้นที่ขาย</label>
                    <input type="number" name="shares" step="any" min="0.0000001" placeholder="เช่น 0.5 (เศษหุ้นได้)" disabled
                        class="sell-field w-full border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm bg-white/70 focus:outline-none focus:ring-2 focus:ring-indigo-400">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-600 mb-1.5">ราคาขาย/หุ้น</label>
                    <input type="number" name="sell_price" step="any" min="0.0000001" placeholder="ราคาที่ขายได้จริง" disabled
                        class="sell-field w-full border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm bg-white/70 focus:outline-none focus:ring-2 focus:ring-indigo-400">
                    <p class="text-xs text-slate-400 mt-1">สกุลของหุ้น (ไทย=บาท, US=ดอลลาร์)</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-600 mb-1.5">สกุลเงินที่ได้รับ</label>
                    <div class="grid grid-cols-2 gap-2">
                        @foreach(['THB' => '🇹🇭 บาท', 'USD' => '🇺🇸 ดอลลาร์'] as $code => $label)
                        <label class="cursor-pointer">
                            <input type="radio" name="sell_currency" value="{{ $code }}" {{ $code === 'THB' ? 'checked' : '' }} disabled class="sell-field peer sr-only">
                            <div class="border border-slate-200 peer-checked:border-indigo-500 peer-checked:bg-indigo-50 rounded-xl py-2.5 text-center text-sm font-medium text-slate-600 peer-checked:text-indigo-700 transition">{{ $label }}</div>
                        </label>
                        @endforeach
                    </div>
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-600 mb-1.5">วันที่ <span class="text-slate-400 font-normal">(ไม่บังคับ — default วันนี้)</span></label>
                <input type="date" name="purchase_date" max="{{ now()->toDateString() }}"
                    class="w-full border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm bg-white/70 focus:outline-none focus:ring-2 focus:ring-indigo-400">
                <p class="text-xs text-slate-400 mt-1">ระบบดึงราคา + อัตราแลกเปลี่ยนวันนั้นมาคำนวณให้</p>
                @error('purchase_date') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-600 mb-1.5">เรทแลกเปลี่ยน (FX) <span class="text-slate-400 font-normal">(ไม่บังคับ)</span></label>
                <input type="number" name="fx_rate" step="any" min="1" max="200" placeholder="เว้นว่าง = เรทตลาดวันนั้น"
                    class="w-full border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm bg-white/70 focus:outline-none focus:ring-2 focus:ring-indigo-400">
                <p class="text-xs text-slate-400 mt-1">1 USD = ? บาท · กรอกเรทจริงจากโบรก (เฉพาะรายการสกุล USD) — เว้นว่าง = ใช้เรท Yahoo</p>
            </div>
            <button type="submit" id="addItemSubmit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2.5 rounded-xl text-sm transition shadow-sm active:scale-[0.98]">
                + เพิ่มเข้าพอร์ต
            </button>
        </form>
    </div>

    {{-- รายการถือครอง + chart + AI --}}
    <div class="lg:col-span-2 space-y-6">
        @if(empty($transactions))
            <div class="glass-card flex flex-col items-center justify-center py-20 text-center">
                <div class="w-16 h-16 bg-gradient-to-br from-indigo-100 to-purple-100 rounded-2xl flex items-center justify-center mb-4 text-3xl">💼</div>
                <p class="text-slate-500 font-medium">พอร์ตยังว่าง</p>
                <p class="text-slate-400 text-sm mt-1">เพิ่มหุ้นที่ถือจริงจากฟอร์มด้านซ้าย</p>
            </div>
        @else
            @if(!empty($positions))
            {{-- Allocation donut + legend --}}
            <div class="glass-card p-6">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 items-center">
                    <div>
                        <h3 class="font-semibold text-slate-800 mb-3">สัดส่วนพอร์ต</h3>
                        <canvas id="allocChart" height="200"></canvas>
                    </div>
                    <div class="space-y-2">
                        @foreach($positions as $i => $a)
                        <div class="flex items-center justify-between text-sm">
                            <div class="flex items-center gap-2">
                                <span class="w-3 h-3 rounded-full" style="background: var(--c{{ $i }})"></span>
                                <span class="font-medium text-slate-700">{{ $a['symbol'] }}</span>
                            </div>
                            <span class="text-slate-500">{{ number_format($a['allocation'], 1) }}%</span>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- ตารางสรุปรายหุ้น (สถานะสุทธิหลังหักขาย, ต้นทุนเฉลี่ย) เรียงตามสัดส่วนมาก→น้อย --}}
            <div class="glass-card p-6">
                <h3 class="font-semibold text-slate-800 mb-4">สรุปรายหุ้น (คงเหลือ)</h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-slate-400 text-left border-b border-slate-100">
                            <tr>
                                <th class="pb-2 font-medium">หุ้น</th>
                                <th class="pb-2 font-medium text-right">ต้นทุน (บาท)</th>
                                <th class="pb-2 font-medium text-right">มูลค่า (บาท)</th>
                                <th class="pb-2 font-medium text-right">กำไร/ขาดทุน</th>
                                <th class="pb-2 font-medium text-right">สัดส่วน</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            @foreach($positions as $i => $a)
                            @php $pos = $a['unrealized_pl_thb'] >= 0; @endphp
                            <tr>
                                <td class="py-2.5">
                                    <div class="flex items-center gap-2">
                                        <span class="w-2.5 h-2.5 rounded-full shrink-0" style="background: var(--c{{ $i }})"></span>
                                        <span class="font-semibold text-slate-700">{{ $a['symbol'] }}</span>
                                    </div>
                                    <span class="text-xs text-slate-400 ml-4.5">{{ rtrim(rtrim(number_format($a['net_shares'], 7), '0'), '.') }} หุ้น</span>
                                </td>
                                <td class="py-2.5 text-right text-slate-600 tabular-nums">{{ number_format($a['cost_thb'], 0) }}</td>
                                <td class="py-2.5 text-right font-medium text-slate-800 tabular-nums">{{ number_format($a['value_thb'], 0) }}</td>
                                <td class="py-2.5 text-right tabular-nums {{ $pos ? 'text-emerald-600' : 'text-red-500' }}">
                                    <div class="font-medium">{{ $pos ? '+' : '' }}{{ number_format($a['unrealized_pl_thb'], 0) }}</div>
                                    <div class="text-xs">{{ $pos ? '+' : '' }}{{ number_format($a['unrealized_pl_pct'], 1) }}%</div>
                                </td>
                                <td class="py-2.5 text-right text-slate-600 tabular-nums">{{ number_format($a['allocation'], 1) }}%</td>
                            </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="border-t border-slate-200">
                            <tr class="text-slate-700 font-semibold">
                                <td class="pt-3">รวม</td>
                                <td class="pt-3 text-right tabular-nums">{{ number_format($total_cost_thb, 0) }}</td>
                                <td class="pt-3 text-right tabular-nums">{{ number_format($total_value_thb, 0) }}</td>
                                @php $tpos = $total_unrealized_pl >= 0; @endphp
                                <td class="pt-3 text-right tabular-nums {{ $tpos ? 'text-emerald-600' : 'text-red-500' }}">
                                    <div>{{ $tpos ? '+' : '' }}{{ number_format($total_unrealized_pl, 0) }}</div>
                                    <div class="text-xs">{{ $tpos ? '+' : '' }}{{ number_format($total_unrealized_pct, 1) }}%</div>
                                </td>
                                <td class="pt-3 text-right">100%</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            @else
            <div class="glass-card p-5 text-center text-sm text-slate-500">
                ขายหุ้นออกหมดแล้ว — ไม่มีหุ้นคงเหลือในพอร์ต (กำไรที่รับรู้แล้วแสดงด้านบน)
            </div>
            @endif

            {{-- ตารางถือครอง / ledger ธุรกรรม (AJAX pagination) --}}
            <div class="glass-card p-6" id="holdingsContainer">
                @include('portfolio._holdings')
            </div>

            {{-- AI Health Check --}}
            <div class="glass-card p-6">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h3 class="font-semibold text-slate-800">🩺 ตรวจสุขภาพพอร์ตด้วย AI</h3>
                        <p id="healthAt" class="text-xs text-slate-400 mt-0.5 {{ $latestHealthAt ? '' : 'hidden' }}">
                            วิเคราะห์ล่าสุด: <span id="healthAtValue">{{ $latestHealthAt }}</span>
                        </p>
                    </div>
                    <button id="healthBtn" class="bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-700 hover:to-purple-700 text-white font-medium px-4 py-2 rounded-xl text-sm transition shadow-sm active:scale-[0.98] disabled:opacity-60">
                        {{ $latestHealthHtml ? 'วิเคราะห์ใหม่' : 'วิเคราะห์พอร์ต' }}
                    </button>
                </div>
                <div id="healthLoading" class="hidden flex-col items-center py-10 text-center">
                    <div class="relative w-12 h-12 mb-3">
                        <div class="absolute inset-0 rounded-full border-4 border-indigo-100"></div>
                        <div class="absolute inset-0 rounded-full border-4 border-indigo-500 border-t-transparent animate-spin"></div>
                    </div>
                    <p class="text-slate-500 text-sm">AI กำลังตรวจสุขภาพพอร์ต...</p>
                </div>
                {{-- แสดงผลวิเคราะห์ล่าสุด (ถ้ามี) --}}
                <div id="healthResult" class="md-content text-sm text-slate-600">{!! $latestHealthHtml !!}</div>
            </div>
        @endif
    </div>
</div>

{{-- Modal แก้ไขรายการถือครอง --}}
<div id="editModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40 backdrop-blur-sm p-4">
    <div class="glass-card !bg-white w-full max-w-md p-6 max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between mb-5">
            <h3 class="font-bold text-slate-800">แก้ไข <span id="editSymbol" class="text-indigo-600"></span></h3>
            <button type="button" id="editClose" class="w-8 h-8 flex items-center justify-center text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded-lg transition">✕</button>
        </div>

        <form id="editForm" method="POST" class="space-y-4">
            @csrf
            @method('PUT')
            <input type="hidden" name="mode" id="editMode" value="amount">

            {{-- โหมด --}}
            <div class="grid grid-cols-2 gap-2">
                <button type="button" data-mode="amount" class="edit-mode-btn border border-slate-200 rounded-xl py-2.5 text-xs font-medium text-slate-600 transition">💵 ตามจำนวนเงิน</button>
                <button type="button" data-mode="shares" class="edit-mode-btn border border-slate-200 rounded-xl py-2.5 text-xs font-medium text-slate-600 transition">📊 ตามจำนวนหุ้น</button>
            </div>

            {{-- โหมดจำนวนเงิน --}}
            <div id="editAmountGroup" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-slate-600 mb-1.5">จำนวนเงินที่ลงทุน</label>
                    <input type="number" name="invested_amount" id="editInvested" step="any" min="1"
                        class="w-full border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-400">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-600 mb-1.5">สกุลเงินที่จ่าย</label>
                    <div class="grid grid-cols-2 gap-2">
                        @foreach(['THB' => '🇹🇭 บาท', 'USD' => '🇺🇸 ดอลลาร์'] as $code => $label)
                        <label class="cursor-pointer">
                            <input type="radio" name="invested_currency" value="{{ $code }}" class="peer sr-only edit-cur">
                            <div class="border border-slate-200 peer-checked:border-indigo-500 peer-checked:bg-indigo-50 rounded-xl py-2.5 text-center text-sm font-medium text-slate-600 peer-checked:text-indigo-700 transition">{{ $label }}</div>
                        </label>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- โหมดจำนวนหุ้น --}}
            <div id="editSharesGroup" class="space-y-4 hidden">
                <div>
                    <label class="block text-sm font-medium text-slate-600 mb-1.5">จำนวนหุ้นที่ถืออยู่</label>
                    <input type="number" name="shares" id="editShares" step="any" min="0.0000001" disabled
                        class="w-full border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-400">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-600 mb-1.5">ต้นทุนเฉลี่ย/หุ้น <span class="text-slate-400 font-normal">(ไม่บังคับ)</span></label>
                    <input type="number" name="avg_cost" id="editAvgCost" step="any" min="0" disabled
                        class="w-full border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-400">
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-600 mb-1.5">วันที่</label>
                <input type="date" name="purchase_date" id="editDate" max="{{ now()->toDateString() }}"
                    class="w-full border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-400">
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-600 mb-1.5">เรท FX <span class="text-slate-400 font-normal">(ไม่บังคับ — เว้นว่าง = เรทตลาด)</span></label>
                <input type="number" name="fx_rate" id="editFx" step="any" min="1" max="200" placeholder="1 USD = ? บาท"
                    class="w-full border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-400">
            </div>

            <div class="flex gap-2 pt-2">
                <button type="button" id="editCancel" class="flex-1 border border-slate-200 text-slate-600 font-medium py-2.5 rounded-xl text-sm hover:bg-slate-50 transition">ยกเลิก</button>
                <button type="submit" class="flex-1 bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2.5 rounded-xl text-sm transition shadow-sm">บันทึก</button>
            </div>
        </form>
    </div>
</div>

{{-- Modal นำเข้าจากภาพ --}}
<div id="importModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40 backdrop-blur-sm p-4">
    <div class="glass-card !bg-white w-full max-w-2xl p-6 max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between mb-5">
            <h3 class="font-bold text-slate-800">📷 นำเข้าจากภาพหน้าจอโบรก</h3>
            <button type="button" id="importClose" class="w-8 h-8 flex items-center justify-center text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded-lg transition">✕</button>
        </div>

        {{-- ขั้น 1: อัปโหลด --}}
        <div id="importStep1">
            <label class="block border-2 border-dashed border-slate-200 rounded-xl p-6 text-center cursor-pointer hover:border-indigo-300 transition">
                <input type="file" id="importFiles" accept="image/*" multiple class="hidden">
                <div class="text-3xl mb-2">🖼️</div>
                <p class="text-sm font-medium text-slate-600">เลือกภาพรายการซื้อ (เลือกได้หลายภาพ)</p>
                <p id="importFileCount" class="text-xs text-slate-400 mt-1">รองรับ PNG/JPG สูงสุด 8 ภาพ</p>
            </label>
            <button type="button" id="importParseBtn" disabled
                class="w-full mt-4 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed text-white font-medium py-2.5 rounded-xl text-sm transition">
                🔍 อ่านภาพด้วย AI
            </button>
        </div>

        {{-- loading --}}
        <div id="importLoading" class="hidden flex-col items-center py-10 text-center">
            <div class="relative w-12 h-12 mb-3">
                <div class="absolute inset-0 rounded-full border-4 border-indigo-100"></div>
                <div class="absolute inset-0 rounded-full border-4 border-indigo-500 border-t-transparent animate-spin"></div>
            </div>
            <p class="text-slate-500 text-sm">AI กำลังอ่านรายการจากภาพ...</p>
        </div>

        {{-- ขั้น 2: preview --}}
        <div id="importStep2" class="hidden">
            <div id="importNewStocks" class="hidden bg-amber-50 border border-amber-200 text-amber-700 text-sm rounded-xl px-4 py-3 mb-4"></div>
            <p class="text-xs text-slate-500 mb-2">ตรวจสอบ/แก้ไขข้อมูลก่อนบันทึก — ติ๊กรายการที่ต้องการเพิ่ม (รายการซ้ำไม่ถูกติ๊กให้)</p>
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead class="text-slate-400 text-left border-b border-slate-100">
                        <tr>
                            <th class="pb-2 pr-2"><input type="checkbox" id="importCheckAll" class="rounded border-slate-300"></th>
                            <th class="pb-2 pr-2 font-medium">หุ้น</th>
                            <th class="pb-2 pr-2 font-medium">จำนวนหุ้น</th>
                            <th class="pb-2 pr-2 font-medium">ราคา/หุ้น</th>
                            <th class="pb-2 pr-2 font-medium">มูลค่าที่จ่าย</th>
                            <th class="pb-2 pr-2 font-medium">เรท FX</th>
                            <th class="pb-2 font-medium">วันเวลา</th>
                        </tr>
                    </thead>
                    <tbody id="importRows" class="divide-y divide-slate-50"></tbody>
                </table>
            </div>
            <div class="flex gap-2 pt-4">
                <button type="button" id="importBackBtn" class="flex-1 border border-slate-200 text-slate-600 font-medium py-2.5 rounded-xl text-sm hover:bg-slate-50 transition">← เลือกภาพใหม่</button>
                <button type="button" id="importConfirmBtn" class="flex-1 bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2.5 rounded-xl text-sm transition shadow-sm">บันทึกเข้าพอร์ต</button>
            </div>
        </div>
    </div>
</div>

@endsection

@push('scripts')
{{-- สลับโหมดเพิ่มหุ้น (เงิน / จำนวนหุ้น) — แสดงตลอดแม้พอร์ตว่าง --}}
<script>
function switchMode(mode) {
    const amt = document.getElementById('mode-amount');
    const shr = document.getElementById('mode-shares');
    const amtInputs = amt.querySelectorAll('input');
    const shrInputs = shr.querySelectorAll('input');

    if (mode === 'shares') {
        shr.classList.remove('hidden'); amt.classList.add('hidden');
        shrInputs.forEach(i => i.disabled = false);
        amtInputs.forEach(i => i.disabled = true);
        document.querySelector('[name=shares]').required = true;
        document.querySelector('[name=invested_amount]').required = false;
    } else {
        amt.classList.remove('hidden'); shr.classList.add('hidden');
        amtInputs.forEach(i => i.disabled = false);
        shrInputs.forEach(i => i.disabled = true);
        document.querySelector('[name=invested_amount]').required = true;
        document.querySelector('[name=shares]').required = false;
    }
}
// ── สลับ ซื้อ/ขาย ──
function switchType(type) {
    document.getElementById('itemType').value = type;
    const buy = document.getElementById('buyFields');
    const sell = document.getElementById('sellFields');
    const submit = document.getElementById('addItemSubmit');
    document.querySelectorAll('.type-btn').forEach(b => {
        const on = b.dataset.type === type;
        b.classList.toggle('border-indigo-500', on);
        b.classList.toggle('bg-indigo-50', on);
        b.classList.toggle('text-indigo-700', on);
    });
    if (type === 'sell') {
        buy.classList.add('hidden'); sell.classList.remove('hidden');
        buy.querySelectorAll('input').forEach(i => i.disabled = true);
        sell.querySelectorAll('.sell-field').forEach(i => i.disabled = false);
        sell.querySelector('[name=shares]').required = true;
        sell.querySelector('[name=sell_price]').required = true;
        submit.textContent = '🔴 บันทึกการขาย';
        submit.classList.remove('bg-indigo-600','hover:bg-indigo-700');
        submit.classList.add('bg-red-500','hover:bg-red-600');
    } else {
        sell.classList.add('hidden'); buy.classList.remove('hidden');
        sell.querySelectorAll('input').forEach(i => i.disabled = true);
        switchMode(document.querySelector('[name=mode]:checked')?.value || 'amount');
        submit.textContent = '+ เพิ่มเข้าพอร์ต';
        submit.classList.add('bg-indigo-600','hover:bg-indigo-700');
        submit.classList.remove('bg-red-500','hover:bg-red-600');
    }
}
document.querySelectorAll('.type-btn').forEach(b => b.addEventListener('click', () => switchType(b.dataset.type)));

document.addEventListener('DOMContentLoaded', () => switchType('buy'));

// ── สร้างพอร์ตใหม่ (Swal prompt) ──
document.getElementById('newPortfolioBtn')?.addEventListener('click', async function () {
    const { value } = await Swal.fire({
        title: 'สร้างพอร์ตใหม่',
        input: 'text',
        inputLabel: 'ชื่อพอร์ต',
        inputPlaceholder: 'เช่น พอร์ตเกษียณ, พอร์ตหุ้นซิ่ง',
        showCancelButton: true,
        confirmButtonText: 'สร้าง',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#4f46e5',
        customClass: { popup: 'rounded-2xl' },
        inputValidator: v => (!v || !v.trim()) ? 'กรุณาใส่ชื่อพอร์ต' : null,
    });
    if (value && value.trim()) {
        document.getElementById('newPortfolioName').value = value.trim();
        document.getElementById('newPortfolioForm').submit();
    }
});

// ── เปลี่ยนชื่อพอร์ต (Swal prompt prefill ชื่อเดิม) ──
document.getElementById('renamePortfolioBtn')?.addEventListener('click', async function () {
    const { value } = await Swal.fire({
        title: 'แก้ไขชื่อพอร์ต',
        input: 'text',
        inputLabel: 'ชื่อพอร์ต',
        inputValue: this.dataset.name || '',
        showCancelButton: true,
        confirmButtonText: 'บันทึก',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#4f46e5',
        customClass: { popup: 'rounded-2xl' },
        inputValidator: v => (!v || !v.trim()) ? 'กรุณาใส่ชื่อพอร์ต' : null,
    });
    if (value && value.trim()) {
        document.getElementById('renamePortfolioName').value = value.trim();
        document.getElementById('renamePortfolioForm').submit();
    }
});

// ── Edit modal ──
function editSwitchMode(mode) {
    document.getElementById('editMode').value = mode;
    const aG = document.getElementById('editAmountGroup');
    const sG = document.getElementById('editSharesGroup');
    document.querySelectorAll('.edit-mode-btn').forEach(b => {
        const on = b.dataset.mode === mode;
        b.classList.toggle('border-indigo-500', on);
        b.classList.toggle('bg-indigo-50', on);
        b.classList.toggle('text-indigo-700', on);
    });
    if (mode === 'shares') {
        sG.classList.remove('hidden'); aG.classList.add('hidden');
        sG.querySelectorAll('input').forEach(i => i.disabled = false);
        aG.querySelectorAll('input').forEach(i => i.disabled = true);
        document.getElementById('editShares').required = true;
        document.getElementById('editInvested').required = false;
    } else {
        aG.classList.remove('hidden'); sG.classList.add('hidden');
        aG.querySelectorAll('input').forEach(i => i.disabled = false);
        sG.querySelectorAll('input').forEach(i => i.disabled = true);
        document.getElementById('editInvested').required = true;
        document.getElementById('editShares').required = false;
    }
}

function openEditModal(btn) {
    const d = btn.dataset;
    document.getElementById('editForm').action = `{{ url('portfolio/items') }}/${d.id}`;
    document.getElementById('editSymbol').textContent = d.symbol;
    document.getElementById('editInvested').value = d.invested || '';
    document.getElementById('editShares').value = d.shares || '';
    document.getElementById('editAvgCost').value = (d.avgCost && parseFloat(d.avgCost) > 0) ? d.avgCost : '';
    document.getElementById('editFx').value = (d.fx && parseFloat(d.fx) > 0) ? d.fx : '';
    document.querySelectorAll('.edit-cur').forEach(r => r.checked = (r.value === (d.currency || 'THB')));

    const dateEl = document.getElementById('editDate');
    if (dateEl._flatpickr) dateEl._flatpickr.setDate(d.date || null, false);
    else dateEl.value = d.date || '';

    editSwitchMode(d.mode || 'amount');

    const m = document.getElementById('editModal');
    m.classList.remove('hidden'); m.classList.add('flex');
}
function closeEditModal() {
    const m = document.getElementById('editModal');
    m.classList.add('hidden'); m.classList.remove('flex');
}

document.addEventListener('click', function (e) {
    const editBtn = e.target.closest('.edit-item-btn');
    if (editBtn) { openEditModal(editBtn); return; }
    const modeBtn = e.target.closest('.edit-mode-btn');
    if (modeBtn) { e.preventDefault(); editSwitchMode(modeBtn.dataset.mode); return; }
});
document.getElementById('editClose')?.addEventListener('click', closeEditModal);
document.getElementById('editCancel')?.addEventListener('click', closeEditModal);
document.getElementById('editModal')?.addEventListener('click', e => { if (e.target.id === 'editModal') closeEditModal(); });

// ── นำเข้าจากภาพ (Gemini Vision) ──
(function () {
    const modal = document.getElementById('importModal');
    if (!modal) return;
    const fileInput  = document.getElementById('importFiles');
    const fileCount  = document.getElementById('importFileCount');
    const parseBtn   = document.getElementById('importParseBtn');
    const step1      = document.getElementById('importStep1');
    const step2      = document.getElementById('importStep2');
    const loadingEl  = document.getElementById('importLoading');
    const rowsEl     = document.getElementById('importRows');
    const newStocks  = document.getElementById('importNewStocks');
    const checkAll   = document.getElementById('importCheckAll');
    const csrf       = document.querySelector('meta[name=csrf-token]').content;

    function showStep(n) {
        step1.classList.toggle('hidden', n !== 1);
        step2.classList.toggle('hidden', n !== 2);
        loadingEl.classList.toggle('hidden', n !== 0);
        loadingEl.classList.toggle('flex', n === 0);
    }
    function openModal()  { modal.classList.remove('hidden'); modal.classList.add('flex'); showStep(1); }
    function closeModal() { modal.classList.add('hidden'); modal.classList.remove('flex'); fileInput.value = ''; fileCount.textContent = 'รองรับ PNG/JPG สูงสุด 8 ภาพ'; parseBtn.disabled = true; }

    document.getElementById('importImageBtn')?.addEventListener('click', openModal);
    document.getElementById('importClose').addEventListener('click', closeModal);
    document.getElementById('importBackBtn').addEventListener('click', () => showStep(1));
    modal.addEventListener('click', e => { if (e.target.id === 'importModal') closeModal(); });

    fileInput.addEventListener('change', () => {
        const n = fileInput.files.length;
        parseBtn.disabled = n === 0;
        fileCount.textContent = n ? `เลือกแล้ว ${n} ภาพ` : 'รองรับ PNG/JPG สูงสุด 8 ภาพ';
    });

    parseBtn.addEventListener('click', async () => {
        if (!fileInput.files.length) return;
        const fd = new FormData();
        [...fileInput.files].slice(0, 8).forEach(f => fd.append('images[]', f));
        showStep(0);
        try {
            const res = await fetch('{{ route('portfolio.import.parse') }}', {
                method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' }, body: fd,
            });
            const data = await res.json();
            if (!data.success) { window.toast('error', data.message || 'อ่านภาพไม่สำเร็จ'); showStep(1); return; }
            renderRows(data);
            showStep(2);
        } catch (e) { window.toast('error', 'เกิดข้อผิดพลาด: ' + e.message); showStep(1); }
    });

    function renderRows(data) {
        if (data.new_stocks?.length) {
            newStocks.textContent = '➕ เพิ่มหุ้นใหม่เข้าระบบให้แล้ว: ' + data.new_stocks.join(', ');
            newStocks.classList.remove('hidden');
        } else { newStocks.classList.add('hidden'); }

        rowsEl.innerHTML = '';
        if (!data.rows.length) {
            rowsEl.innerHTML = '<tr><td colspan="7" class="py-4 text-center text-slate-400">ไม่พบรายการในภาพ</td></tr>';
            return;
        }
        data.rows.forEach(r => {
            const dup = r.status === 'duplicate', inv = r.status === 'invalid';
            const tag = dup ? ' <span class="text-amber-500 font-normal">(ซ้ำ)</span>'
                : inv ? ' <span class="text-red-500 font-normal">(ไม่พบหุ้น)</span>' : '';
            const d = inv ? 'disabled' : '';
            const curOpt = c => `<option value="THB" ${r.currency==='THB'?'selected':''}>THB</option><option value="USD" ${r.currency==='USD'?'selected':''}>USD</option>`;
            const sell = r.type === 'sell';
            const typeBadge = `<span class="text-xs px-1.5 py-0.5 rounded-full ${sell?'bg-red-50 text-red-600':'bg-emerald-50 text-emerald-600'}">${sell?'ขาย':'ซื้อ'}</span>`;
            const tr = document.createElement('tr');
            tr.className = inv ? 'opacity-50' : '';
            tr.dataset.stockId = r.stock_id || '';
            tr.dataset.type = r.type || 'buy';
            tr.innerHTML = `
                <td class="py-2 pr-2"><input type="checkbox" class="imp-chk rounded border-slate-300" ${(!dup && !inv) ? 'checked' : ''} ${d}></td>
                <td class="py-2 pr-2 font-semibold text-slate-700 whitespace-nowrap">${typeBadge} ${r.symbol}${tag}</td>
                <td class="py-2 pr-2"><input type="number" step="any" value="${r.shares}" class="imp-shares w-24 border border-slate-200 rounded-lg px-2 py-1" ${d}></td>
                <td class="py-2 pr-2"><input type="number" step="any" value="${r.price}" class="imp-price w-20 border border-slate-200 rounded-lg px-2 py-1" ${d}></td>
                <td class="py-2 pr-2 whitespace-nowrap">
                    <input type="number" step="any" value="${r.amount}" class="imp-amount w-20 border border-slate-200 rounded-lg px-2 py-1" ${d}>
                    <select class="imp-cur border border-slate-200 rounded-lg px-1 py-1" ${d}>${curOpt()}</select>
                </td>
                <td class="py-2 pr-2"><input type="number" step="any" value="${r.fx_rate || ''}" placeholder="ตลาด" class="imp-fx w-16 border border-slate-200 rounded-lg px-2 py-1" ${d}></td>
                <td class="py-2"><input type="text" value="${r.datetime || ''}" class="imp-dt w-40 border border-slate-200 rounded-lg px-2 py-1" ${d}></td>`;
            rowsEl.appendChild(tr);
        });
    }

    checkAll?.addEventListener('change', () => {
        rowsEl.querySelectorAll('.imp-chk:not([disabled])').forEach(c => c.checked = checkAll.checked);
    });

    document.getElementById('importConfirmBtn').addEventListener('click', async () => {
        const rows = [];
        rowsEl.querySelectorAll('tr').forEach(tr => {
            const chk = tr.querySelector('.imp-chk');
            if (!chk || !chk.checked) return;
            rows.push({
                type:     tr.dataset.type,
                stock_id: tr.dataset.stockId,
                shares:   tr.querySelector('.imp-shares').value,
                price:    tr.querySelector('.imp-price').value,
                amount:   tr.querySelector('.imp-amount').value,
                currency: tr.querySelector('.imp-cur').value,
                fx_rate:  tr.querySelector('.imp-fx').value,
                datetime: tr.querySelector('.imp-dt').value,
            });
        });
        if (!rows.length) { window.toast('warning', 'ยังไม่ได้เลือกรายการ'); return; }
        try {
            const res = await fetch('{{ route('portfolio.import.confirm') }}', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'Content-Type': 'application/json' },
                body: JSON.stringify({ rows }),
            });
            const data = await res.json();
            if (data.success) { location.reload(); } // flash จาก server จะโชว์ toast หลัง reload
            else { window.toast('error', data.message || 'บันทึกไม่สำเร็จ'); }
        } catch (e) { window.toast('error', 'เกิดข้อผิดพลาด: ' + e.message); }
    });
})();
</script>
@endpush

@push('scripts')
@if(!empty($transactions))
<script>
document.addEventListener('DOMContentLoaded', function () {
    const palette = ['#6366f1','#10b981','#f43f5e','#f59e0b','#06b6d4','#a855f7','#ec4899','#84cc16'];
    // ตั้ง CSS var ให้ legend สีตรงกับ chart
    palette.forEach((c, i) => document.documentElement.style.setProperty('--c' + i, c));

    const allocEl = document.getElementById('allocChart');
    const labels = @json(array_map(fn($a) => $a['symbol'], $positions));
    const values = @json(array_map(fn($a) => round($a['value_thb'], 2), $positions));

    if (allocEl && labels.length) new Chart(allocEl, {
        type: 'doughnut',
        data: {
            labels,
            datasets: [{ data: values, backgroundColor: palette, borderWidth: 2, borderColor: '#fff' }]
        },
        options: {
            responsive: true,
            cutout: '62%',
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => ` ${ctx.label}: ${ctx.parsed.toLocaleString('th-TH')} บาท`
                    }
                }
            }
        }
    });

    // AI Health Check
    const btn = document.getElementById('healthBtn');
    const loading = document.getElementById('healthLoading');
    const result = document.getElementById('healthResult');

    btn.addEventListener('click', async function () {
        btn.disabled = true;
        result.textContent = '';
        loading.classList.remove('hidden');
        loading.classList.add('flex');
        try {
            const res = await fetch('{{ route('portfolio.health') }}', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    'Accept': 'application/json',
                },
            });
            const data = await res.json();
            if (data.success) {
                result.innerHTML = data.analysis_html;
                // อัปเดตเวลาวิเคราะห์ล่าสุด + ปุ่มเป็น "วิเคราะห์ใหม่"
                if (data.analyzed_at) {
                    document.getElementById('healthAtValue').textContent = data.analyzed_at;
                    document.getElementById('healthAt').classList.remove('hidden');
                }
                btn.textContent = 'วิเคราะห์ใหม่';
            } else {
                window.toast('error', data.message || 'วิเคราะห์ไม่สำเร็จ');
            }
        } catch (e) {
            window.toast('error', 'เกิดข้อผิดพลาด: ' + e.message);
        } finally {
            loading.classList.add('hidden');
            loading.classList.remove('flex');
            btn.disabled = false;
        }
    });

    // AJAX pagination รายการถือครอง (event delegation เพราะเนื้อหาถูก swap)
    const container = document.getElementById('holdingsContainer');
    container.addEventListener('click', async function (e) {
        const btn = e.target.closest('.pg-btn');
        if (!btn) return;
        const page = btn.dataset.page;
        container.style.opacity = '0.5';
        try {
            const res = await fetch(`{{ route('portfolio.holdings') }}?page=${page}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            container.innerHTML = await res.text();
        } catch (e) {
            window.toast('error', 'โหลดหน้าไม่สำเร็จ');
        } finally {
            container.style.opacity = '1';
        }
    });
});
</script>
@endif
@endpush
