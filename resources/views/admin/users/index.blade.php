@extends('layouts.app')

@section('title', 'จัดการผู้ใช้ — Invest AI')

@section('content')

<div class="flex items-center justify-between mb-8">
    <div>
        <h1 class="text-2xl font-bold text-slate-900">👥 จัดการผู้ใช้</h1>
        <p class="text-slate-500 text-sm mt-1">เพิ่ม/แก้ไขบัญชี กำหนดบทบาท และรีเซ็ตรหัสผ่าน</p>
    </div>
    <div class="flex gap-2">
        <a href="{{ route('admin.roles.index') }}" class="px-4 py-2.5 bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-xl transition">บทบาท / สิทธิ์</a>
        <a href="{{ route('admin.users.create') }}" class="px-4 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-xl transition active:scale-[0.98]">+ เพิ่มผู้ใช้</a>
    </div>
</div>

<div class="glass-card overflow-hidden">
    <table class="w-full text-sm">
        <thead class="bg-slate-50/80 text-slate-500 text-left">
            <tr>
                <th class="px-5 py-3 font-medium">ผู้ใช้</th>
                <th class="px-5 py-3 font-medium">บทบาท</th>
                <th class="px-5 py-3 font-medium">บอทแชต</th>
                <th class="px-5 py-3 font-medium">สถานะ</th>
                <th class="px-5 py-3 font-medium text-right">จัดการ</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @foreach($users as $u)
            <tr class="hover:bg-slate-50/50">
                <td class="px-5 py-3">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center text-white text-xs font-semibold">
                            {{ mb_substr($u->nickname ?: $u->name, 0, 1) }}
                        </div>
                        <div>
                            <p class="font-medium text-slate-800">{{ $u->name }}</p>
                            <p class="text-xs text-slate-400">{{ $u->email }}</p>
                        </div>
                    </div>
                </td>
                <td class="px-5 py-3">
                    @if($u->role)
                        <span class="text-xs bg-indigo-50 text-indigo-600 px-2 py-0.5 rounded-full">{{ $u->role->name }}</span>
                    @else
                        <span class="text-xs text-slate-400">— ยังไม่กำหนด —</span>
                    @endif
                </td>
                <td class="px-5 py-3">
                    @if($u->messaging_chat_id)
                        <span class="text-xs text-emerald-600">● ผูกแล้ว ({{ $u->messaging_provider === 'telegram' ? 'Telegram' : 'LINE' }})</span>
                    @else
                        <span class="text-xs text-slate-400">○ ยังไม่ผูก</span>
                    @endif
                </td>
                <td class="px-5 py-3">
                    @if($u->status)
                        <span class="text-xs bg-emerald-50 text-emerald-600 px-2 py-0.5 rounded-full">ใช้งาน</span>
                    @else
                        <span class="text-xs bg-rose-50 text-rose-600 px-2 py-0.5 rounded-full">ปิดใช้งาน</span>
                    @endif
                </td>
                <td class="px-5 py-3">
                    <div class="flex items-center justify-end gap-2">
                        <a href="{{ route('admin.users.edit', $u) }}" class="text-indigo-600 hover:text-indigo-700 text-xs font-medium">แก้ไข</a>
                        @if($u->id !== auth()->id())
                        <form method="POST" action="{{ route('admin.users.destroy', $u) }}" class="confirm-delete inline"
                            data-title="ลบผู้ใช้ {{ $u->name }}?" data-message="ข้อมูลพอร์ตและหุ้นที่ติดตามจะถูกลบด้วย">
                            @csrf @method('DELETE')
                            <button type="submit" class="text-rose-600 hover:text-rose-700 text-xs font-medium">ลบ</button>
                        </form>
                        @endif
                    </div>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>

@endsection
