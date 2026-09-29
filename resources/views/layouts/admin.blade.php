<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Admin' }} · Skripsi Uploader</title>
    <link rel="icon" type="image/png" href="{{ asset('images/logo-unand.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-canvas font-sans text-slate-700 antialiased">
@php
    $user = auth()->user();
    $rail = [
        ['admin.dashboard', 'admin.dashboard', 'Dashboard', 'home', false],
        ['admin.submissions', 'admin.submissions*', 'Unggahan', 'document', false],
        ['admin.settings.appearance', 'admin.settings.*', 'Pengaturan', 'cog', true],
        ['admin.users', 'admin.users', 'Pengguna Admin', 'users', true],
        ['admin.logs', 'admin.logs', 'Log Aktivitas', 'activity', true],
    ];
    $settingsTabs = [
        ['admin.settings.appearance', 'Tampilan'],
        ['admin.settings.form', 'Field Form'],
        ['admin.settings.faculties', 'Fakultas'],
        ['admin.settings.ai', 'AI & Kriteria'],
        ['admin.settings.cloud', 'Cloud'],
        ['admin.settings.whatsapp', 'WhatsApp'],
        ['admin.settings.dspace', 'DSpace'],
    ];
    $initials = collect(explode(' ', $user->name))->filter()->take(2)->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->implode('');
@endphp

<div class="mx-auto max-w-[1500px] p-2 sm:p-5">
    <div class="flex gap-5 rounded-[2.5rem] bg-white/60 p-3 shadow-float ring-1 ring-white/70 sm:p-4">

        {{-- Floating icon rail (bottom bar on phones) --}}
        <aside class="fixed inset-x-3 bottom-3 z-30 flex items-center justify-around rounded-3xl bg-gradient-to-b from-brand-500 to-brand-700 px-2 py-2 shadow-float
                      md:sticky md:top-5 md:inset-auto md:h-[calc(100vh-4.5rem)] md:w-20 md:flex-col md:justify-start md:gap-3 md:py-5">
            <a href="{{ route('admin.dashboard') }}" wire:navigate class="hidden h-12 w-12 items-center justify-center rounded-2xl bg-white p-1 shadow-soft md:mb-4 md:flex" title="Perpustakaan Universitas Andalas">
                <img src="{{ asset('images/logo-unand.png') }}" alt="Logo Universitas Andalas" class="h-full w-full object-contain">
            </a>
            @foreach ($rail as [$route, $pattern, $label, $icon, $superOnly])
                @if (! $superOnly || $user->isSuperadmin())
                    @php $active = request()->routeIs($pattern); @endphp
                    <a href="{{ route($route) }}" wire:navigate title="{{ $label }}"
                       class="group relative flex h-12 w-12 items-center justify-center rounded-2xl transition
                              {{ $active ? 'bg-brand-900/60 text-white shadow-inner' : 'text-brand-100 hover:bg-white/15 hover:text-white' }}">
                        <x-icon :name="$icon" class="h-[22px] w-[22px]"/>
                        <span class="sr-only">{{ $label }}</span>
                        <span class="pointer-events-none absolute left-16 z-40 hidden whitespace-nowrap rounded-lg bg-slate-900 px-2 py-1 text-xs text-white opacity-0 transition group-hover:opacity-100 md:block">{{ $label }}</span>
                    </a>
                @endif
            @endforeach
            <form method="POST" action="{{ route('admin.logout') }}" class="md:mt-auto">@csrf
                <button title="Keluar" class="flex h-12 w-12 items-center justify-center rounded-2xl text-brand-100 hover:bg-white/15 hover:text-white">
                    <x-icon name="logout" class="h-[22px] w-[22px]"/><span class="sr-only">Keluar</span>
                </button>
            </form>
        </aside>

        {{-- Main --}}
        <main class="min-w-0 flex-1 pb-24 md:pb-2">
            <header class="mb-5 flex flex-wrap items-center gap-3 px-1 pt-1 sm:gap-4">
                <x-brand size="sm" class="mr-auto"/>
                <form method="GET" action="{{ route('admin.submissions') }}" class="order-last w-full sm:order-none sm:w-72">
                    <label class="relative block">
                        <span class="sr-only">Cari unggahan</span>
                        <x-icon name="search" class="pointer-events-none absolute top-1/2 left-3.5 h-4 w-4 -translate-y-1/2 text-slate-400"/>
                        <input name="search" value="{{ request('search') }}" placeholder="Cari NIM, nama, fakultas…"
                               class="w-full rounded-2xl border-0 bg-white py-2.5 pr-4 pl-10 text-sm shadow-soft placeholder:text-slate-400 focus:ring-4 focus:ring-brand-100 focus:outline-none">
                    </label>
                </form>
                <div class="flex items-center gap-3">
                    <div class="hidden text-right sm:block">
                        <p class="text-sm font-medium text-slate-700">{{ $user->name }}</p>
                        <p class="text-xs text-slate-400">{{ \App\Models\User::ROLES[$user->role] ?? $user->role }}</p>
                    </div>
                    <span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-gradient-to-br from-accent-400 to-brand-500 text-sm font-semibold text-white shadow-soft">{{ $initials }}</span>
                </div>
            </header>

            <div class="mb-5 px-1">
                <p class="text-xs text-slate-400">Skripsi Uploader</p>
                <h1 class="text-xl font-semibold text-slate-800 sm:text-2xl">{{ $title ?? 'Admin' }}</h1>
            </div>

            @if (request()->routeIs('admin.settings.*'))
                <nav class="mb-5 flex gap-1 overflow-x-auto rounded-2xl bg-white p-1.5 shadow-soft" aria-label="Pengaturan">
                    @foreach ($settingsTabs as [$route, $label])
                        <a href="{{ route($route) }}" wire:navigate
                           class="shrink-0 rounded-xl px-4 py-2 text-sm font-medium transition {{ request()->routeIs($route) ? 'bg-brand-600 text-white shadow-soft' : 'text-slate-500 hover:bg-brand-50 hover:text-brand-700' }}">{{ $label }}</a>
                    @endforeach
                </nav>
            @endif

            {{ $slot }}
        </main>
    </div>
</div>
</body>
</html>
