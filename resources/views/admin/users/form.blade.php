@extends('layouts.app')

@section('title', ($user->exists ? 'แก้ไขผู้ใช้' : 'เพิ่มผู้ใช้') . ' — Invest AI')

@section('content')

@php $editing = $user->exists; @endphp

<div class="mb-8">
    <a href="{{ route('admin.users.index') }}" class="text-sm text-slate-400 hover:text-slate-600">← กลับไปรายการผู้ใช้</a>
    <h1 class="text-2xl font-bold text-slate-900 mt-2">{{ $editing ? '✏️ แก้ไขผู้ใช้' : '➕ เพิ่มผู้ใช้' }}</h1>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 max-w-4xl">
    {{-- ข้อมูลผู้ใช้ --}}
    <div class="glass-card p-6 self-start">
        <form method="POST" action="{{ $editing ? route('admin.users.update', $user) : route('admin.users.store') }}" class="space-y-4">
            @csrf
            @if($editing) @method('PUT') @endif

            <div>
                <label class="block text-sm font-medium text-slate-600 mb-1.5">ชื่อเล่น</label>
                <input type="text" name="nickname" value="{{ old('nickname', $user->nickname) }}" required
                    class="w-full border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm bg-white/70 focus:outline-none focus:ring-2 focus:ring-indigo-400">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-600 mb-1.5">อีเมล</label>
                <input type="email" name="email" value="{{ old('email', $user->email) }}" required
                    class="w-full border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm bg-white/70 focus:outline-none focus:ring-2 focus:ring-indigo-400">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-600 mb-1.5">บทบาท</label>
                <select name="role_id" required
                    class="w-full border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm bg-white/70 focus:outline-none focus:ring-2 focus:ring-indigo-400">
                    <option value="">— เลือกบทบาท —</option>
                    @foreach($roles as $r)
                        <option value="{{ $r->id }}" @selected(old('role_id', $user->role_id) == $r->id)>{{ $r->name }}</option>
                    @endforeach
                </select>
            </div>

            @unless($editing)
            <div>
                <label class="block text-sm font-medium text-slate-600 mb-1.5">รหัสผ่าน</label>
                <input type="password" name="password" required autocomplete="new-password"
                    class="w-full border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm bg-white/70 focus:outline-none focus:ring-2 focus:ring-indigo-400">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-600 mb-1.5">ยืนยันรหัสผ่าน</label>
                <input type="password" name="password_confirmation" required autocomplete="new-password"
                    class="w-full border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm bg-white/70 focus:outline-none focus:ring-2 focus:ring-indigo-400">
            </div>
            @endunless

            <label class="flex items-center gap-2 text-sm font-medium text-slate-600">
                <input type="checkbox" name="status" value="1" @checked(old('status', $user->status ?? true))
                    class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-400 w-5 h-5">
                เปิดใช้งานบัญชี
            </label>

            <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium px-6 py-2.5 rounded-xl text-sm transition active:scale-[0.98]">
                {{ $editing ? 'บันทึกการแก้ไข' : 'เพิ่มผู้ใช้' }}
            </button>
        </form>
    </div>

    {{-- รีเซ็ตรหัสผ่าน (เฉพาะตอนแก้ไข) --}}
    @if($editing)
    <div class="glass-card p-6 self-start">
        <h2 class="font-semibold text-slate-800 mb-5 flex items-center gap-2">🔑 รีเซ็ตรหัสผ่าน</h2>
        <form method="POST" action="{{ route('admin.users.reset-password', $user) }}" class="space-y-4">
            @csrf @method('PUT')
            <div>
                <label class="block text-sm font-medium text-slate-600 mb-1.5">รหัสผ่านใหม่</label>
                <input type="password" name="password" required autocomplete="new-password"
                    class="w-full border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm bg-white/70 focus:outline-none focus:ring-2 focus:ring-indigo-400">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-600 mb-1.5">ยืนยันรหัสผ่านใหม่</label>
                <input type="password" name="password_confirmation" required autocomplete="new-password"
                    class="w-full border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm bg-white/70 focus:outline-none focus:ring-2 focus:ring-indigo-400">
            </div>
            <button type="submit" class="bg-slate-700 hover:bg-slate-800 text-white font-medium px-6 py-2.5 rounded-xl text-sm transition active:scale-[0.98]">รีเซ็ตรหัสผ่าน</button>
        </form>
    </div>
    @endif
</div>

@endsection
