{{-- ตารางรายการถือครอง + pager (ใช้ทั้ง initial load และ AJAX) --}}
<div class="flex items-center justify-between mb-4">
    <h3 class="font-semibold text-slate-800">รายการถือครอง</h3>
    <span class="text-xs text-slate-400">ทั้งหมด {{ number_format($holdingsPage['total']) }} รายการ</span>
</div>

<div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-xs text-slate-500 uppercase border-b border-slate-200">
                <th class="pb-2 pr-3">หุ้น</th>
                <th class="pb-2 pr-3 text-right">เงินลงทุน</th>
                <th class="pb-2 pr-3 text-right">หุ้นที่ได้</th>
                <th class="pb-2 pr-3 text-right">ราคาซื้อ→ล่าสุด</th>
                <th class="pb-2 pr-3 text-right">มูลค่า (บาท)</th>
                <th class="pb-2 pr-3 text-right">กำไร/ขาดทุน</th>
                <th class="pb-2"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @foreach($holdingsPage['items'] as $h)
            <tr>
                <td class="py-2.5 pr-3">
                    <span class="font-semibold text-slate-800">{{ $h['symbol'] }}</span>
                    <span class="text-xs text-slate-400 block">{{ $h['purchase_date'] ?? '—' }}</span>
                </td>
                <td class="py-2.5 pr-3 text-right text-slate-600">
                    {{ $h['invested_amount'] ? number_format($h['invested_amount'], 0) . ' ' . $h['invested_currency'] : '—' }}
                </td>
                {{-- shares 7 ตำแหน่ง ตัด 0 ท้ายออกให้อ่านง่าย --}}
                <td class="py-2.5 pr-3 text-right text-slate-600">{{ rtrim(rtrim(number_format($h['shares'], 7), '0'), '.') }}</td>
                <td class="py-2.5 pr-3 text-right text-slate-500 text-xs">
                    {{ number_format($h['purchase_price'], 2) }} → {{ number_format($h['current_price'], 2) }} {{ $h['currency'] }}
                </td>
                <td class="py-2.5 pr-3 text-right font-medium text-slate-800">{{ number_format($h['value_thb'], 0) }}</td>
                <td class="py-2.5 pr-3 text-right font-semibold {{ $h['pl_value_thb'] >= 0 ? 'text-emerald-600' : 'text-red-500' }}">
                    {{ $h['pl_value_thb'] >= 0 ? '+' : '' }}{{ number_format($h['pl_percent'], 1) }}%
                    <span class="block text-xs font-normal {{ $h['pl_value_thb'] >= 0 ? 'text-emerald-500' : 'text-red-400' }}">
                        {{ $h['pl_value_thb'] >= 0 ? '+' : '' }}{{ number_format($h['pl_value_thb'], 0) }} บาท
                    </span>
                </td>
                <td class="py-2.5 text-right">
                    <form method="POST" action="{{ route('portfolio.items.destroy', $h['id']) }}" class="inline confirm-delete"
                          data-title="ลบ {{ $h['symbol'] }}?" data-message="ลบรายการนี้ออกจากพอร์ต">
                        @csrf @method('DELETE')
                        <button type="submit" class="p-1.5 text-slate-300 hover:text-red-500 hover:bg-red-50 rounded-lg transition">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                        </button>
                    </form>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>

{{-- Pager (AJAX) --}}
@if($holdingsPage['pages'] > 1)
<div class="flex items-center justify-between mt-4 pt-4 border-t border-slate-100 text-sm">
    <div>
        @if($holdingsPage['page'] > 1)
            <button class="pg-btn px-3 py-1.5 rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50 transition" data-page="{{ $holdingsPage['page'] - 1 }}">‹ ก่อนหน้า</button>
        @endif
    </div>
    <span class="text-slate-400">หน้า {{ $holdingsPage['page'] }} / {{ $holdingsPage['pages'] }}</span>
    <div>
        @if($holdingsPage['page'] < $holdingsPage['pages'])
            <button class="pg-btn px-3 py-1.5 rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50 transition" data-page="{{ $holdingsPage['page'] + 1 }}">ถัดไป ›</button>
        @endif
    </div>
</div>
@endif
