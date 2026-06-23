@extends('layouts.app')

@section('title', 'บทบาท / สิทธิ์ — Stock AI')

@section('content')

<div class="flex items-center justify-between mb-8">
    <div>
        <h1 class="text-2xl font-bold text-slate-900">🛡️ บทบาท / สิทธิ์</h1>
        <p class="text-slate-500 text-sm mt-1">กำหนดว่าบทบาทใดเข้าถึงเมนูใดได้บ้าง</p>
    </div>
    <div class="flex gap-2">
        <a href="{{ route('admin.users.index') }}" class="px-4 py-2.5 bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-xl transition">← ผู้ใช้</a>
        <a href="{{ route('admin.roles.create') }}" class="px-4 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-xl transition active:scale-[0.98]">+ เพิ่มบทบาท</a>
    </div>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
    @foreach($roles as $role)
    <div class="glass-card p-5">
        <div class="flex items-start justify-between">
            <div>
                <h3 class="font-semibold text-slate-800 flex items-center gap-2">
                    {{ $role->name }}
                    @if($role->is_super)<span class="text-xs bg-amber-100 text-amber-700 px-2 py-0.5 rounded-full">Super</span>@endif
                    @if($role->is_protected)<span class="text-xs bg-slate-100 text-slate-500 px-2 py-0.5 rounded-full">ป้องกัน</span>@endif
                </h3>
                <p class="text-xs text-slate-400 mt-0.5">{{ $role->description ?: 'ไม่มีคำอธิบาย' }} · {{ $role->users_count }} ผู้ใช้</p>
            </div>
            <div class="flex gap-2 shrink-0">
                @unless($role->is_protected)
                <a href="{{ route('admin.roles.edit', $role) }}" class="text-indigo-600 hover:text-indigo-700 text-xs font-medium">แก้ไข</a>
                <form method="POST" action="{{ route('admin.roles.destroy', $role) }}" class="confirm-delete inline"
                    data-title="ลบบทบาท {{ $role->name }}?">
                    @csrf @method('DELETE')
                    <button type="submit" class="text-rose-600 hover:text-rose-700 text-xs font-medium">ลบ</button>
                </form>
                @endunless
            </div>
        </div>
        <div class="flex flex-wrap gap-1.5 mt-3">
            @if($role->is_super)
                <span class="text-xs bg-emerald-50 text-emerald-600 px-2 py-0.5 rounded-full">ทุกเมนู</span>
            @else
                @forelse($role->permissions ?? [] as $perm)
                    <span class="text-xs bg-slate-100 text-slate-600 px-2 py-0.5 rounded-full">{{ \App\Models\Role::MENU_GROUPS[$perm]['label'] ?? $perm }}</span>
                @empty
                    <span class="text-xs text-slate-400">— ไม่มีสิทธิ์เข้าเมนูใด —</span>
                @endforelse
            @endif
        </div>
    </div>
    @endforeach
</div>

@endsection
