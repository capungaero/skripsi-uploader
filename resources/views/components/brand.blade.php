@props(['light' => false, 'size' => 'md'])
@php
    $logo = ['sm' => 'h-9 w-9', 'md' => 'h-12 w-12', 'lg' => 'h-16 w-16'][$size];
    $title = ['sm' => 'text-sm', 'md' => 'text-base', 'lg' => 'text-lg sm:text-xl'][$size];
@endphp
{{-- Universitas Andalas logo with the library name. --}}
<div {{ $attributes->merge(['class' => 'flex items-center gap-3']) }}>
    <span class="flex {{ $logo }} shrink-0 items-center justify-center rounded-2xl bg-white p-1 shadow-soft">
        <img src="{{ asset('images/logo-unand.png') }}" alt="Logo Universitas Andalas" class="h-full w-full object-contain">
    </span>
    <div class="leading-tight">
        <p class="{{ $title }} font-medium {{ $light ? 'text-white/85' : 'text-brand-600' }}">Perpustakaan</p>
        <p class="{{ $title }} font-semibold {{ $light ? 'text-white' : 'text-slate-800' }}">Universitas Andalas</p>
    </div>
</div>
