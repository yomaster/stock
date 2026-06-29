@extends('layouts.app')

@section('title', ($role->exists ? 'แก้ไขบทบาท' : 'เพิ่มบทบาท') . ' — Invest AI')

@section('content')

@php
    $editing = $role->exists;
    $current = old('permissions', $role->permissions ?? []);
@endphp

<div class="mb-8">
    <a href="{{ route('admin.roles.index') }}" class="text-sm text-slate-400 hover:text-slate-600">← กลับไปรายการบทบาท</a>
    <h1 class="text-2xl font-bold text-slate-900 mt-2">{{ $editing ? '✏️ แก้ไขบทบาท' : '➕ เพิ่มบทบาท' }}</h1>
</div>

<form method="POST" action="{{ $editing ? route('admin.roles.update', $role) : route('admin.roles.store') }}" class="max-w-2xl">
    @csrf
    @if($editing) @method('PUT') @endif

    <div class="glass-card p-6 space-y-5">
        <div>
            <label class="block text-sm font-medium text-slate-600 mb-1.5">ชื่อบทบาท</label>
            <input type="text" name="name" value="{{ old('name', $role->name) }}" required
                class="w-full border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm bg-white/70 focus:outline-none focus:ring-2 focus:ring-indigo-400">
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-600 mb-1.5">คำอธิบาย</label>
            <input type="text" name="description" value="{{ old('description', $role->description) }}"
                class="w-full border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm bg-white/70 focus:outline-none focus:ring-2 focus:ring-indigo-400">
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-600 mb-2.5">สิทธิ์เข้าถึงเมนู</label>
            <div class="space-y-4">
                @foreach(\App\Models\Role::groupedMenuGroups() as $groupName => $items)
                <div>
                    <p class="text-xs font-semibold text-slate-400 uppercase mb-2">{{ $groupName }}</p>
                    <div class="grid grid-cols-2 gap-2">
                        @foreach($items as $key => $meta)
                        <label class="flex items-center gap-2.5 px-3 py-2.5 border border-slate-200 rounded-xl text-sm cursor-pointer hover:bg-slate-50 has-[:checked]:bg-indigo-50 has-[:checked]:border-indigo-300">
                            <input type="checkbox" name="permissions[]" value="{{ $key }}" @checked(in_array($key, $current))
                                class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-400">
                            <span class="text-slate-700">{{ $meta['label'] }}</span>
                        </label>
                        @endforeach
                    </div>
                </div>
                @endforeach
            </div>
        </div>

        <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium px-6 py-2.5 rounded-xl text-sm transition active:scale-[0.98]">
            {{ $editing ? 'บันทึกการแก้ไข' : 'เพิ่มบทบาท' }}
        </button>
    </div>
</form>

@endsection
