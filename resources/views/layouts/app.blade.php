<!DOCTYPE html>
<html lang="th" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Stock AI')</title>

    {{-- Google Sans + Noto Sans Thai --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Google+Sans:wght@400;500;700&family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
<body class="h-full bg-slate-50" style="font-family:'Google Sans','Noto Sans Thai',sans-serif">

<div class="min-h-screen page-bg">
    {{-- Top Navbar --}}
    <header class="sticky top-0 z-50 bg-white/80 backdrop-blur-xl border-b border-slate-200/60 shadow-sm">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-14">

                {{-- Logo --}}
                <a href="{{ route('dashboard') }}" class="flex items-center gap-2.5 group">
                    <div class="w-8 h-8 bg-gradient-to-br from-indigo-500 to-purple-600 rounded-lg flex items-center justify-center shadow-sm group-hover:shadow-indigo-200 transition-all">
                        <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18L9 11.25l4.306 4.307a11.95 11.95 0 015.814-5.519l2.74-1.22m0 0l-5.94-2.28m5.94 2.28l-2.28 5.941"/>
                        </svg>
                    </div>
                    <span class="font-bold text-slate-800 text-base tracking-tight">Stock<span class="text-indigo-600">AI</span></span>
                </a>

                {{-- Nav Links --}}
                <nav class="flex items-center gap-1">
                    @php
                        // 'perm' = menu group ที่ต้องมีสิทธิ์ถึงจะเห็นเมนู (gate ด้วย canAccessMenuGroup)
                        $navItems = [
                            ['route' => 'dashboard',    'perm' => 'dashboard', 'label' => 'Dashboard',  'icon' => 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6'],
                            ['route' => 'stocks.index', 'perm' => 'stocks', 'label' => 'หุ้นทั้งหมด', 'icon' => 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z'],
                            ['route' => 'stocks.compare', 'perm' => 'compare', 'label' => 'เปรียบเทียบ', 'icon' => 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z'],
                            ['route' => 'portfolio.index', 'perm' => 'portfolio', 'label' => 'พอร์ต', 'icon' => 'M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z'],
                            ['route' => 'manage.index', 'perm' => 'manage', 'label' => 'จัดการหุ้น',  'icon' => 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z'],
                            ['route' => 'admin.users.index', 'perm' => 'users', 'label' => 'ผู้ใช้งาน', 'icon' => 'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-4 4 4 0 004 4zm6 0a4 4 0 10-3-6.62'],
                            ['route' => 'settings.index', 'perm' => 'settings', 'label' => 'ตั้งค่า', 'icon' => 'M4.5 12a7.5 7.5 0 0015 0m-15 0a7.5 7.5 0 1115 0m-15 0H3m16.5 0H21m-1.5 0H12m-8.457 3.077l1.41-.513m14.095-5.13l1.41-.513M5.106 17.785l1.15-.964m11.49-9.642l1.149-.964M7.501 19.795l.75-1.3m7.5-12.99l.75-1.3m-6.063 16.658l.26-1.477m2.605-14.772l.26-1.477m0 17.726l-.26-1.477M10.698 4.614l-.26-1.477M16.5 19.794l-.75-1.299M7.5 4.205L12 12m6.894 5.785l-1.149-.964M6.256 7.178l-1.15-.964m15.352 8.864l-1.41-.513M4.954 9.435l-1.41-.514M12.002 12l-3.75 6.495'],
                        ];
                        $user = auth()->user();
                    @endphp
                    @foreach($navItems as $item)
                        @continue($user && !$user->canAccessMenuGroup($item['perm']))
                        @php $active = request()->routeIs($item['route']) @endphp
                        <a href="{{ route($item['route']) }}"
                           class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-medium transition-all
                                  {{ $active
                                      ? 'bg-indigo-50 text-indigo-700'
                                      : 'text-slate-500 hover:text-slate-800 hover:bg-slate-100' }}">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round" d="{{ $item['icon'] }}"/>
                            </svg>
                            {{ $item['label'] }}
                        </a>
                    @endforeach
                </nav>

                {{-- User menu (vanilla JS toggle — ดู script ท้ายไฟล์) --}}
                @auth
                <div class="relative" id="userMenu">
                    <button type="button" data-user-menu-toggle
                        class="flex items-center gap-2 pl-2 pr-1 py-1 rounded-lg hover:bg-slate-100 transition-all">
                        <div class="w-7 h-7 rounded-full bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center text-white text-xs font-semibold">
                            {{ mb_substr($user->nickname ?: $user->name, 0, 1) }}
                        </div>
                        <span class="text-sm font-medium text-slate-700 hidden sm:inline">{{ $user->nickname ?: $user->name }}</span>
                        <svg class="w-4 h-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div data-user-menu-panel
                        class="hidden absolute right-0 mt-2 w-52 bg-white rounded-xl shadow-lg border border-slate-100 py-1.5 z-50">
                        <div class="px-4 py-2 border-b border-slate-100">
                            <p class="text-sm font-medium text-slate-800 truncate">{{ $user->name }}</p>
                            <p class="text-xs text-slate-400 truncate">{{ $user->email }}</p>
                            @if($user->role)
                                <span class="inline-block mt-1 text-xs bg-indigo-50 text-indigo-600 px-2 py-0.5 rounded-full">{{ $user->role->name }}</span>
                            @endif
                        </div>
                        <a href="{{ route('profile.index') }}" class="flex items-center gap-2 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                            โปรไฟล์ของฉัน
                        </a>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="w-full flex items-center gap-2 px-4 py-2 text-sm text-rose-600 hover:bg-rose-50">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                                ออกจากระบบ
                            </button>
                        </form>
                    </div>
                </div>
                @endauth
            </div>
        </div>
    </header>

    {{-- Flash Messages → ส่งให้ SweetAlert toast (ใน app.js) --}}
    @if(session('success'))
        <script>window.__flash = {type: 'success', msg: @json(session('success'))};</script>
    @elseif(session('error'))
        <script>window.__flash = {type: 'error', msg: @json(session('error'))};</script>
    @endif

    {{-- Main --}}
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        @yield('content')
    </main>

    <footer class="mt-12 pb-8 text-center text-slate-400 text-xs">
        Stock AI &mdash; ข้อมูลเพื่อการศึกษาเท่านั้น ไม่ใช่คำแนะนำการลงทุน
    </footer>
</div>

{{-- User menu dropdown toggle (vanilla) --}}
<script>
document.addEventListener('DOMContentLoaded', function () {
    const menu = document.getElementById('userMenu');
    if (!menu) return;
    const btn = menu.querySelector('[data-user-menu-toggle]');
    const panel = menu.querySelector('[data-user-menu-panel]');
    btn.addEventListener('click', function (e) {
        e.stopPropagation();
        panel.classList.toggle('hidden');
    });
    document.addEventListener('click', function (e) {
        if (!menu.contains(e.target)) panel.classList.add('hidden');
    });
});
</script>

@stack('scripts')
</body>
</html>
