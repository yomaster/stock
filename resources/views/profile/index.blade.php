@extends('layouts.app')

@section('title', 'โปรไฟล์ของฉัน — Stock AI')

@section('content')

<div class="mb-8">
    <h1 class="text-2xl font-bold text-slate-900">👤 โปรไฟล์ของฉัน</h1>
    <p class="text-slate-500 text-sm mt-1">จัดการข้อมูลส่วนตัว ผูกบัญชี LINE และตั้งค่าการแจ้งเตือน</p>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

    {{-- ── ข้อมูลโปรไฟล์ ── --}}
    <div class="glass-card p-6 self-start">
        <h2 class="font-semibold text-slate-800 mb-5 flex items-center gap-2">📝 ข้อมูลส่วนตัว</h2>
        <form method="POST" action="{{ route('profile.update') }}" class="space-y-4">
            @csrf @method('PUT')
            <div>
                <label class="block text-sm font-medium text-slate-600 mb-1.5">ชื่อ-สกุล</label>
                <input type="text" name="name" value="{{ old('name', $user->name) }}" required
                    class="w-full border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm bg-white/70 focus:outline-none focus:ring-2 focus:ring-indigo-400">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-600 mb-1.5">ชื่อเล่น (แสดงบนเมนู)</label>
                <input type="text" name="nickname" value="{{ old('nickname', $user->nickname) }}"
                    class="w-full border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm bg-white/70 focus:outline-none focus:ring-2 focus:ring-indigo-400">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-600 mb-1.5">อีเมล</label>
                <input type="email" name="email" value="{{ old('email', $user->email) }}" required
                    class="w-full border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm bg-white/70 focus:outline-none focus:ring-2 focus:ring-indigo-400">
            </div>
            <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium px-5 py-2.5 rounded-xl text-sm transition active:scale-[0.98]">บันทึก</button>
        </form>

        <hr class="my-6 border-slate-100">

        <h2 class="font-semibold text-slate-800 mb-5 flex items-center gap-2">🔑 เปลี่ยนรหัสผ่าน</h2>
        <form method="POST" action="{{ route('profile.password') }}" class="space-y-4">
            @csrf @method('PUT')
            <div>
                <label class="block text-sm font-medium text-slate-600 mb-1.5">รหัสผ่านปัจจุบัน</label>
                <input type="password" name="current_password" required autocomplete="current-password"
                    class="w-full border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm bg-white/70 focus:outline-none focus:ring-2 focus:ring-indigo-400">
            </div>
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
            <button type="submit" class="bg-slate-700 hover:bg-slate-800 text-white font-medium px-5 py-2.5 rounded-xl text-sm transition active:scale-[0.98]">เปลี่ยนรหัสผ่าน</button>
        </form>
    </div>

    {{-- ── LINE + แจ้งเตือน ── --}}
    <div class="space-y-6 self-start">

        {{-- LINE binding --}}
        <div class="glass-card p-6">
            <h2 class="font-semibold text-slate-800 mb-5 flex items-center gap-2">💬 การแจ้งเตือนผ่าน LINE</h2>

            @if($user->line_user_id)
                <div class="flex items-center gap-2 bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm rounded-xl px-4 py-3 mb-4">
                    <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                    ผูกบัญชี LINE เรียบร้อยแล้ว — คุณจะได้รับแจ้งเตือนราคาและสรุปเช้า
                </div>
                <form method="POST" action="{{ route('profile.line.unlink') }}" class="confirm-delete"
                    data-title="ยกเลิกการผูก LINE?" data-message="คุณจะไม่ได้รับแจ้งเตือนทาง LINE อีก">
                    @csrf @method('DELETE')
                    <button type="submit" class="text-sm text-rose-600 hover:text-rose-700 font-medium">ยกเลิกการผูก LINE</button>
                </form>
            @else
                <div class="flex flex-col sm:flex-row gap-5 items-start">
                    <div class="text-center shrink-0">
                        {{-- cache-bust ด้วย filemtime — เปลี่ยนรูป (ชื่อเดิม) แล้ว browser โหลดใหม่อัตโนมัติ --}}
                        <img src="{{ asset('assets/images/lineoa/272awqmv.png') }}?v={{ @filemtime(public_path('assets/images/lineoa/272awqmv.png')) ?: '1' }}" alt="LINE OA QR"
                            class="w-32 h-32 rounded-xl border border-slate-200 bg-white p-1">
                        <p class="text-xs text-slate-400 mt-1.5">สแกนเพื่อแอด LINE OA</p>
                    </div>
                    <div class="text-sm text-slate-600 space-y-2 flex-1">
                        <p class="font-medium text-slate-700">วิธีผูกบัญชี:</p>
                        <ol class="list-decimal list-inside space-y-1 text-slate-500">
                            <li>สแกน QR เพื่อเพิ่มเพื่อน LINE OA</li>
                            <li>กดปุ่ม "สร้างรหัสผูกบัญชี" ด้านล่าง</li>
                            <li>พิมพ์ <code class="bg-slate-100 px-1.5 py-0.5 rounded">/link รหัส</code> ในแชท LINE</li>
                        </ol>

                        @if($user->line_link_code && $user->line_link_code_expires_at && $user->line_link_code_expires_at->isFuture())
                            <div class="bg-indigo-50 border border-indigo-200 rounded-xl px-4 py-3 mt-2">
                                <p class="text-xs text-indigo-500">รหัสของคุณ (หมดอายุ {{ $user->line_link_code_expires_at->format('H:i') }}):</p>
                                <p class="text-2xl font-bold tracking-widest text-indigo-700 font-mono">{{ $user->line_link_code }}</p>
                                <p class="text-xs text-indigo-500 mt-1">พิมพ์ <code>/link {{ $user->line_link_code }}</code> ในแชท</p>
                            </div>
                        @endif

                        <form method="POST" action="{{ route('profile.line.code') }}" class="mt-2">
                            @csrf
                            <button type="submit" class="bg-[#06C755] hover:brightness-95 text-white font-medium px-5 py-2.5 rounded-xl text-sm transition active:scale-[0.98]">
                                สร้างรหัสผูกบัญชี
                            </button>
                        </form>
                    </div>
                </div>
            @endif
        </div>

        {{-- Alert settings --}}
        <div class="glass-card p-6">
            <h2 class="font-semibold text-slate-800 mb-5 flex items-center gap-2">🔔 ตั้งค่าการแจ้งเตือน</h2>
            <form method="POST" action="{{ route('profile.alerts') }}" class="space-y-4">
                @csrf @method('PUT')
                <label class="flex items-center justify-between">
                    <span class="text-sm font-medium text-slate-600">แจ้งเตือนราคา/วอลุ่มผิดปกติ</span>
                    <input type="checkbox" name="alert_enabled" value="1" @checked($user->alert_enabled)
                        class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-400 w-5 h-5">
                </label>
                <label class="flex items-center justify-between">
                    <span class="text-sm font-medium text-slate-600">รับสรุปก่อนตลาดเปิด (เช้า)</span>
                    <input type="checkbox" name="summary_enabled" value="1" @checked($user->summary_enabled)
                        class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-400 w-5 h-5">
                </label>
                <div>
                    <label class="block text-sm font-medium text-slate-600 mb-1.5">เกณฑ์แจ้งเตือนราคา (% เปลี่ยนแปลงต่อวัน)</label>
                    <input type="number" step="0.5" min="0.5" max="50" name="alert_price_threshold" value="{{ old('alert_price_threshold', $user->alert_price_threshold) }}" required
                        class="w-full border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm bg-white/70 focus:outline-none focus:ring-2 focus:ring-indigo-400">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-600 mb-1.5">เกณฑ์แจ้งเตือน Volume (เท่าของค่าเฉลี่ย 20 วัน)</label>
                    <input type="number" step="0.1" min="1" max="20" name="alert_volume_multiplier" value="{{ old('alert_volume_multiplier', $user->alert_volume_multiplier) }}" required
                        class="w-full border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm bg-white/70 focus:outline-none focus:ring-2 focus:ring-indigo-400">
                </div>
                <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium px-5 py-2.5 rounded-xl text-sm transition active:scale-[0.98]">บันทึกการแจ้งเตือน</button>
            </form>
        </div>
    </div>
</div>

@endsection
