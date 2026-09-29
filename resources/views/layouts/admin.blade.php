<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Admin' }} · Skripsi Uploader</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-slate-100 font-sans text-slate-800 antialiased">
@php
    $nav = [
        ['admin.dashboard', 'Dashboard', false],
        ['admin.submissions', 'Unggahan', false],
    ];
    $settingsNav = [
        ['admin.settings.appearance', 'Tampilan'],
        ['admin.settings.form', 'Field Form'],
        ['admin.settings.faculties', 'Fakultas & Prodi'],
        ['admin.settings.ai', 'AI & Kriteria'],
        ['admin.settings.cloud', 'Penyimpanan Cloud'],
        ['admin.settings.whatsapp', 'WhatsApp'],
        ['admin.settings.dspace', 'DSpace'],
        ['admin.users', 'Pengguna Admin'],
        ['admin.logs', 'Log Aktivitas'],
    ];
@endphp
<div x-data="{ open: false }" class="flex min-h-screen">
    <aside :class="open ? 'translate-x-0' : '-translate-x-full'"
           class="fixed inset-y-0 left-0 z-30 w-60 transform bg-brand-800 text-brand-50 transition md:static md:translate-x-0">
        <div class="px-5 py-5">
            <p class="text-xs uppercase tracking-wider text-brand-100/70">Perpustakaan Unand</p>
            <p class="text-lg font-bold">Skripsi Uploader</p>
        </div>
        <nav class="space-y-0.5 px-3 text-sm">
            @foreach ($nav as [$route, $label])
                <a href="{{ route($route) }}" wire:navigate
                   class="block rounded-md px-3 py-2 {{ request()->routeIs($route) ? 'bg-brand-700 font-semibold' : 'hover:bg-brand-700/60' }}">{{ $label }}</a>
            @endforeach
            @can('superadmin')
                <p class="px-3 pt-5 pb-1 text-xs uppercase tracking-wider text-brand-100/60">Pengaturan</p>
                @foreach ($settingsNav as [$route, $label])
                    <a href="{{ route($route) }}" wire:navigate
                       class="block rounded-md px-3 py-2 {{ request()->routeIs($route) ? 'bg-brand-700 font-semibold' : 'hover:bg-brand-700/60' }}">{{ $label }}</a>
                @endforeach
            @endcan
        </nav>
        <div class="mt-6 border-t border-brand-700 px-5 py-4 text-sm">
            <p class="font-medium">{{ auth()->user()->name }}</p>
            <p class="text-xs text-brand-100/70">{{ \App\Models\User::ROLES[auth()->user()->role] ?? auth()->user()->role }}</p>
            <form method="POST" action="{{ route('admin.logout') }}" class="mt-2">@csrf
                <button class="text-xs underline hover:text-white">Keluar</button>
            </form>
        </div>
    </aside>
    <div x-show="open" @click="open = false" class="fixed inset-0 z-20 bg-black/30 md:hidden" x-cloak></div>

    <main class="min-w-0 flex-1">
        <div class="flex items-center gap-3 border-b border-slate-200 bg-white px-4 py-3 md:hidden">
            <button @click="open = true" class="btn-secondary px-2 py-1" aria-label="Menu">☰</button>
            <span class="font-semibold">{{ $title ?? 'Admin' }}</span>
        </div>
        <div class="mx-auto max-w-7xl p-4 sm:p-6">
            {{ $slot }}
        </div>
    </main>
</div>
</body>
</html>
