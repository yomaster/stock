<!DOCTYPE html>
<html lang="th" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ลืมรหัสผ่าน — Invest AI</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Google+Sans:wght@400;500;700&family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-slate-50" style="font-family:'Google Sans','Noto Sans Thai',sans-serif">
<div class="min-h-screen page-bg flex items-center justify-center px-4">
    <div class="w-full max-w-sm">
        <div class="flex flex-col items-center mb-6">
            <h1 class="text-xl font-bold text-slate-800">ลืมรหัสผ่าน</h1>
            <p class="text-sm text-slate-400 mt-1 text-center">กรอกอีเมล เราจะส่งลิงก์รีเซ็ตรหัสผ่านให้</p>
        </div>
        <div class="glass-card p-7">
            @if(session('status'))
                <div class="mb-4 bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm rounded-xl px-4 py-3">{{ session('status') }}</div>
            @endif
            @if($errors->any())
                <div class="mb-4 bg-rose-50 border border-rose-200 text-rose-700 text-sm rounded-xl px-4 py-3">{{ $errors->first() }}</div>
            @endif
            <form method="POST" action="{{ route('password.email') }}" class="space-y-4">
                @csrf
                <div>
                    <label class="block text-sm font-medium text-slate-600 mb-1.5">อีเมล</label>
                    <input type="email" name="email" value="{{ old('email') }}" required autofocus
                        class="w-full border border-slate-200 rounded-xl px-3.5 py-2.5 text-sm bg-white/70 focus:outline-none focus:ring-2 focus:ring-indigo-400">
                </div>
                <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-medium px-6 py-2.5 rounded-xl text-sm transition active:scale-[0.98]">ส่งลิงก์รีเซ็ต</button>
            </form>
            <p class="text-center mt-4"><a href="{{ route('login') }}" class="text-sm text-indigo-600 hover:text-indigo-700">← กลับไปเข้าสู่ระบบ</a></p>
        </div>
    </div>
</div>
</body>
</html>
