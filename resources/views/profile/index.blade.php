@extends('layouts.app')

@section('title', 'โปรไฟล์ของฉัน — Invest AI')

@section('content')

<div class="mb-8">
    <h1 class="text-2xl font-bold text-slate-900">👤 โปรไฟล์ของฉัน</h1>
    <p class="text-slate-500 text-sm mt-1">จัดการข้อมูลส่วนตัว ผูกบัญชี {{ $provider === 'telegram' ? 'Telegram' : 'LINE' }} และตั้งค่าการแจ้งเตือน</p>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

    {{-- ── ข้อมูลโปรไฟล์ ── --}}
    <div class="glass-card p-6 self-start">
        <h2 class="font-semibold text-slate-800 mb-1 flex items-center gap-2">📝 ข้อมูลส่วนตัว</h2>
        <p class="text-xs text-slate-400 mb-5">เพื่อความเป็นส่วนตัว เราเก็บแค่ชื่อเล่น · อีเมลใส่หรือไม่ก็ได้ (ไว้ล็อกอินด้วยรหัสผ่าน)</p>
        <form method="POST" action="{{ route('profile.update') }}" class="space-y-4">
            @csrf @method('PUT')
            <div>
                <label class="block text-sm font-medium text-slate-600 mb-1.5">ชื่อเล่น (แสดงบนเมนู)</label>
                <input type="text" name="nickname" value="{{ old('nickname', $user->nickname) }}" required
                    class="w-full border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm bg-white/70 focus:outline-none focus:ring-2 focus:ring-indigo-400">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-600 mb-1.5">อีเมล <span class="text-slate-400 font-normal">(ไม่บังคับ)</span></label>
                <input type="email" name="email" value="{{ old('email', $user->email) }}"
                    placeholder="เว้นว่างได้ถ้าใช้ Google ล็อกอิน"
                    class="w-full border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm bg-white/70 focus:outline-none focus:ring-2 focus:ring-indigo-400">
            </div>
            <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium px-5 py-2.5 rounded-xl text-sm transition active:scale-[0.98]">บันทึก</button>
        </form>

        <hr class="my-6 border-slate-100">

        {{-- เชื่อมต่อ Google --}}
        <h2 class="font-semibold text-slate-800 mb-3 flex items-center gap-2">🔑 บัญชี Google</h2>
        @if($user->google_id)
            <div class="flex items-center gap-2 bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm rounded-xl px-4 py-3 mb-3">
                ✓ เชื่อมต่อ Google แล้ว — ล็อกอินด้วย Google ได้
            </div>
            <form method="POST" action="{{ route('profile.google.disconnect') }}"
                  class="confirm-delete" data-title="ยกเลิกการเชื่อม Google?" data-message="ต้องมีอีเมล + รหัสผ่านไว้ล็อกอินก่อน ไม่งั้นจะล็อกอินไม่ได้">
                @csrf @method('DELETE')
                <button type="submit" class="text-sm text-red-500 hover:text-red-600 font-medium">ยกเลิกการเชื่อมต่อ</button>
            </form>
        @else
            <p class="text-xs text-slate-400 mb-3">เชื่อมต่อแล้วล็อกอินด้วย Google ได้ โดยใช้บัญชีเดิมนี้ (พอร์ตไม่หาย)</p>
            <a href="{{ route('auth.google') }}"
                class="inline-flex items-center gap-2.5 border border-slate-200 hover:bg-slate-50 text-slate-700 font-medium px-4 py-2 rounded-xl text-sm transition">
                <svg class="w-4 h-4" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 01-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z"/>
                    <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84A11 11 0 0012 23z"/>
                    <path fill="#FBBC05" d="M5.84 14.1a6.6 6.6 0 010-4.2V7.06H2.18a11 11 0 000 9.88l3.66-2.84z"/>
                    <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84C6.71 7.31 9.14 5.38 12 5.38z"/>
                </svg>
                เชื่อมต่อบัญชี Google
            </a>
        @endif

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

        @php
            $providerLabel = $provider === 'telegram' ? 'Telegram' : 'LINE';
            $bound = $user->messaging_chat_id && $user->messaging_provider === $provider;
            $hasCode = $user->messaging_link_code && $user->messaging_link_code_expires_at && $user->messaging_link_code_expires_at->isFuture();
        @endphp

        {{-- Messaging binding (ตาม provider ที่ admin เลือก) --}}
        <div class="glass-card p-6">
            <h2 class="font-semibold text-slate-800 mb-5 flex items-center gap-2">💬 การแจ้งเตือนผ่าน {{ $providerLabel }}</h2>

            @if($bound)
                <div class="flex items-center gap-2 bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm rounded-xl px-4 py-3 mb-4">
                    <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                    ผูกบัญชี {{ $providerLabel }} เรียบร้อยแล้ว — คุณจะได้รับแจ้งเตือนราคาและสรุปเช้า
                </div>
                <form method="POST" action="{{ route('profile.line.unlink') }}" class="confirm-delete"
                    data-title="ยกเลิกการผูก {{ $providerLabel }}?" data-message="คุณจะไม่ได้รับแจ้งเตือนทาง {{ $providerLabel }} อีก">
                    @csrf @method('DELETE')
                    <button type="submit" class="text-sm text-rose-600 hover:text-rose-700 font-medium">ยกเลิกการผูก {{ $providerLabel }}</button>
                </form>
            @else
                @if($user->messaging_chat_id && $user->messaging_provider !== $provider)
                    <div class="bg-amber-50 border border-amber-200 text-amber-700 text-sm rounded-xl px-4 py-3 mb-4">
                        บัญชีคุณเคยผูกกับ {{ strtoupper($user->messaging_provider) }} — ตอนนี้ระบบใช้ {{ $providerLabel }} กรุณาผูกใหม่
                    </div>
                @endif
                <div class="flex flex-col sm:flex-row gap-5 items-start">
                    <div class="text-center shrink-0">
                        @if($provider === 'line')
                            {{-- cache-bust ด้วย filemtime — เปลี่ยนรูป (ชื่อเดิม) แล้ว browser โหลดใหม่อัตโนมัติ --}}
                            <img src="{{ asset('assets/images/lineoa/272awqmv.png') }}?v={{ @filemtime(public_path('assets/images/lineoa/272awqmv.png')) ?: '1' }}" alt="LINE OA QR"
                                class="w-32 h-32 rounded-xl border border-slate-200 bg-white p-1">
                            <p class="text-xs text-slate-400 mt-1.5">สแกนเพื่อแอด LINE OA</p>
                        @else
                            <div class="w-32 h-32 rounded-xl border border-slate-200 bg-[#229ED9]/5 flex items-center justify-center text-5xl">✈️</div>
                            @if($telegramBot)
                                <a href="https://t.me/{{ $telegramBot }}" target="_blank" rel="noopener"
                                   class="inline-block mt-2 bg-[#229ED9] hover:brightness-95 text-white text-xs font-medium px-3 py-1.5 rounded-lg">เปิดบอท @{{ $telegramBot }}</a>
                            @else
                                <p class="text-xs text-amber-600 mt-1.5">admin ยังไม่ตั้ง bot username</p>
                            @endif
                        @endif
                    </div>
                    <div class="text-sm text-slate-600 space-y-2 flex-1">
                        <p class="font-medium text-slate-700">วิธีผูกบัญชี:</p>
                        <ol class="list-decimal list-inside space-y-1 text-slate-500">
                            <li>{{ $provider === 'line' ? 'สแกน QR เพื่อเพิ่มเพื่อน LINE OA' : 'เปิดบอท Telegram แล้วกด Start' }}</li>
                            <li>กดปุ่ม "สร้างรหัสผูกบัญชี" ด้านล่าง</li>
                            <li>พิมพ์ <code class="bg-slate-100 px-1.5 py-0.5 rounded">/link รหัส</code> ในแชท {{ $providerLabel }}</li>
                        </ol>

                        @if($hasCode)
                            <div class="bg-indigo-50 border border-indigo-200 rounded-xl px-4 py-3 mt-2">
                                <p class="text-xs text-indigo-500">รหัสของคุณ (หมดอายุ {{ $user->messaging_link_code_expires_at->format('H:i') }}):</p>
                                <p class="text-2xl font-bold tracking-widest text-indigo-700 font-mono">{{ $user->messaging_link_code }}</p>
                                <p class="text-xs text-indigo-500 mt-1">พิมพ์ <code>/link {{ $user->messaging_link_code }}</code> ในแชท</p>
                            </div>
                        @endif

                        <form method="POST" action="{{ route('profile.line.code') }}" class="mt-2">
                            @csrf
                            <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium px-5 py-2.5 rounded-xl text-sm transition active:scale-[0.98]">
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
