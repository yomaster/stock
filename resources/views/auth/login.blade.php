<!DOCTYPE html>
<html lang="th" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เข้าสู่ระบบ — Stock AI</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Google+Sans:wght@400;500;700&family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-slate-50" style="font-family:'Google Sans','Noto Sans Thai',sans-serif">
<div class="min-h-screen page-bg flex items-center justify-center px-4">
    <div class="w-full max-w-sm">
        {{-- Logo --}}
        <div class="flex flex-col items-center mb-6">
            <div class="w-12 h-12 bg-gradient-to-br from-indigo-500 to-purple-600 rounded-2xl flex items-center justify-center shadow-lg shadow-indigo-200 mb-3">
                <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18L9 11.25l4.306 4.307a11.95 11.95 0 015.814-5.519l2.74-1.22m0 0l-5.94-2.28m5.94 2.28l-2.28 5.941"/>
                </svg>
            </div>
            <h1 class="text-xl font-bold text-slate-800 tracking-tight">Stock<span class="text-indigo-600">AI</span></h1>
            <p class="text-sm text-slate-400 mt-1">เข้าสู่ระบบเพื่อใช้งาน</p>
        </div>

        <div class="glass-card p-7">
            @if($errors->any())
                <div class="mb-4 bg-rose-50 border border-rose-200 text-rose-700 text-sm rounded-xl px-4 py-3">
                    {{ $errors->first() }}
                </div>
            @endif
            @if(session('status'))
                <div class="mb-4 bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm rounded-xl px-4 py-3">
                    {{ session('status') }}
                </div>
            @endif

            <form method="POST" action="{{ route('login') }}" class="space-y-4">
                @csrf
                <div>
                    <label class="block text-sm font-medium text-slate-600 mb-1.5">อีเมล</label>
                    <input type="email" name="email" value="{{ old('email') }}" required autofocus
                        class="w-full border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm text-slate-800
                               focus:outline-none focus:ring-2 focus:ring-indigo-400 focus:border-transparent transition bg-white/70">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-600 mb-1.5">รหัสผ่าน</label>
                    <input type="password" name="password" required
                        class="w-full border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm text-slate-800
                               focus:outline-none focus:ring-2 focus:ring-indigo-400 focus:border-transparent transition bg-white/70">
                </div>
                <div class="flex items-center justify-between">
                    <label class="flex items-center gap-2 text-sm text-slate-500">
                        <input type="checkbox" name="remember" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-400">
                        จดจำฉันไว้
                    </label>
                    <a href="{{ route('password.request') }}" class="text-sm text-indigo-600 hover:text-indigo-700">ลืมรหัสผ่าน?</a>
                </div>
                <button type="submit"
                    class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-medium px-6 py-2.5 rounded-xl text-sm transition-all shadow-sm hover:shadow-indigo-200 hover:shadow-lg active:scale-[0.98]">
                    เข้าสู่ระบบ
                </button>
            </form>
        </div>

        <p class="text-center text-slate-400 text-xs mt-6">
            Stock AI &mdash; ข้อมูลเพื่อการศึกษาเท่านั้น ไม่ใช่คำแนะนำการลงทุน
        </p>
    </div>
</div>
</body>
</html>
