<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login Admin · Skripsi Uploader</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css'])
</head>
<body class="flex min-h-screen items-center justify-center bg-canvas px-4 font-sans text-slate-700 antialiased">
<div class="w-full max-w-sm rounded-[2.5rem] bg-white/60 p-3 shadow-float ring-1 ring-white/70 backdrop-blur">
    <div class="card-violet relative overflow-hidden px-6 pt-6 pb-10">
        <svg class="absolute right-0 bottom-0 h-20 w-44 text-white/10" viewBox="0 0 176 80" fill="currentColor" aria-hidden="true"><path d="M0 80 C40 20 70 70 110 30 S160 10 176 0 V80Z"/></svg>
        <span class="icon-badge-soft mb-4"><x-icon name="library" class="h-6 w-6"/></span>
        <p class="text-xs text-brand-100">Perpustakaan Universitas Andalas</p>
        <h1 class="text-xl font-semibold">Skripsi Uploader</h1>
    </div>
    <form method="POST" action="{{ route('admin.login') }}" class="card relative -mt-6 space-y-4 p-6">
        @csrf
        @if ($errors->any())
            <div class="rounded-2xl bg-accent-100 px-4 py-3 text-sm text-accent-600">{{ $errors->first() }}</div>
        @endif
        <div>
            <label for="email" class="label">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username" class="field-input">
        </div>
        <div>
            <label for="password" class="label">Password</label>
            <input id="password" name="password" type="password" required autocomplete="current-password" class="field-input">
        </div>
        <label class="flex items-center gap-2 text-sm text-slate-500"><input type="checkbox" name="remember" value="1" class="rounded accent-brand-600"> Ingat saya</label>
        <button class="btn-primary w-full py-3">Masuk</button>
    </form>
</div>
</body>
</html>
