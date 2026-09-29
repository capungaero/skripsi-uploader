<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login Admin · Skripsi Uploader</title>
    @vite(['resources/css/app.css'])
</head>
<body class="flex min-h-screen items-center justify-center bg-slate-100 px-4 font-sans text-slate-800">
<form method="POST" action="{{ route('admin.login') }}" class="card w-full max-w-sm space-y-4 p-6">
    @csrf
    <div>
        <p class="text-xs uppercase tracking-wider text-slate-500">Perpustakaan Universitas Andalas</p>
        <h1 class="text-xl font-bold text-brand-800">Login Admin Skripsi</h1>
    </div>
    @if ($errors->any())
        <div class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ $errors->first() }}</div>
    @endif
    <div>
        <label for="email" class="label">Email</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username" class="field-input">
    </div>
    <div>
        <label for="password" class="label">Password</label>
        <input id="password" name="password" type="password" required autocomplete="current-password" class="field-input">
    </div>
    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="remember" value="1"> Ingat saya</label>
    <button class="btn-primary w-full">Masuk</button>
</form>
</body>
</html>
