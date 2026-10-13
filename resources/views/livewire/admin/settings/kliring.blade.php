<div class="max-w-3xl space-y-6">
    <div>
        <p class="text-sm text-slate-600">Setelah unggahan di-approve, permohonan kliring mahasiswa di <a href="{{ $baseUrl }}" target="_blank" rel="noopener" class="underline">{{ $baseUrl }}</a> diterima otomatis (syarat "Mengunggah karya ilmiah" divalidasi, lalu <em>Terima Permohonan</em>). Login kliring memakai CAPTCHA, jadi sesi disambungkan di sini oleh admin; sistem menjaga sesi tetap hidup dan meminta sambung ulang jika sesi berakhir.</p>
    </div>
    @if ($flash)<div class="rounded-2xl bg-brand-50 px-4 py-3 text-sm text-brand-800">{{ $flash }}</div>@endif
    @if ($failure)<div class="rounded-2xl bg-accent-100 px-4 py-3 text-sm text-red-700">{{ $failure }}</div>@endif

    <section class="card space-y-4 p-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="font-semibold">Sesi kliring</h2>
                @if (! $configured)
                    <p class="text-sm text-slate-500">Akun belum diatur. Isi <code>KLIRING_USERNAME</code> dan <code>KLIRING_PASSWORD</code> di <code>.env</code>.</p>
                @else
                    <p class="text-sm text-slate-500">Akun <span class="font-mono">{{ $username }}</span></p>
                @endif
            </div>
            @if ($configured)
                <span class="pill {{ $connected ? 'bg-emerald-50 text-emerald-600' : 'bg-accent-100 text-accent-600' }}">
                    {{ $connected ? 'Tersambung' : ($connected === null ? 'Tidak diketahui' : 'Belum tersambung') }}
                </span>
            @endif
        </div>

        @if ($configured)
            @if ($captcha)
                <form wire:submit="login" class="space-y-3">
                    <div class="flex flex-wrap items-end gap-3">
                        <img src="{{ $captcha }}" alt="Kode verifikasi kliring" class="h-14 rounded-xl border border-slate-200 bg-white p-1">
                        <button type="button" wire:click="connect" class="text-xs text-brand-700 underline">Gambar lain</button>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <input wire:model="code" class="field-input w-48" placeholder="Kode verifikasi" autocomplete="off" autofocus>
                        <button class="btn-primary" wire:loading.attr="disabled">Masuk</button>
                    </div>
                    @error('code')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                </form>
            @else
                <div class="flex flex-wrap gap-2">
                    <button wire:click="connect" wire:loading.attr="disabled" class="btn-primary">{{ $connected ? 'Sambungkan ulang' : 'Sambungkan' }}</button>
                    @if ($connected)
                        <button wire:click="disconnect" wire:confirm="Putuskan sesi kliring? Approve otomatis akan menunggu sampai disambungkan lagi." class="btn-secondary">Putuskan</button>
                    @endif
                </div>
            @endif
        @endif
    </section>

    <section class="card space-y-3 p-5">
        <h2 class="font-semibold">Status approve kliring</h2>
        <ul class="grid gap-2 text-sm sm:grid-cols-2">
            @foreach (\App\Models\Submission::KLIRING_LABELS as $key => $label)
                <li class="flex items-center justify-between rounded-2xl bg-surface px-3 py-2"><span>{{ $label }}</span><span class="font-semibold tabular-nums">{{ $counts[$key] ?? 0 }}</span></li>
            @endforeach
        </ul>
        @if (($counts['waiting_login'] ?? 0) + ($counts['waiting_request'] ?? 0) > 0 && $connected)
            <button wire:click="processWaiting" class="btn-secondary">Proses yang menunggu sekarang</button>
        @endif
        @if ($problems->isNotEmpty())
            <ul class="divide-y divide-slate-100 text-sm">
                @foreach ($problems as $s)
                    <li class="py-2">
                        <a href="{{ route('admin.submissions.show', $s) }}" wire:navigate class="font-medium hover:underline">{{ $s->nim }} · {{ $s->nama }}</a>
                        <p class="text-xs text-slate-500">{{ $s->kliringLabel() }}@if ($s->kliring_error) — {{ $s->kliring_error }}@endif</p>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    @if ($configured && $connected)
        <form wire:submit="checkNim" class="card space-y-3 p-5">
            <h2 class="font-semibold">Cek NIM di kliring</h2>
            <p class="text-xs text-slate-500">Hanya membaca, tidak mengubah apa pun di kliring.</p>
            <div class="flex flex-wrap gap-2">
                <input wire:model="nim" class="field-input w-56" placeholder="NIM">
                <button class="btn-secondary" wire:loading.attr="disabled">Cek</button>
            </div>
            @error('nim')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
            @if ($lookup)<p class="rounded-2xl bg-surface px-4 py-3 text-sm">{{ $lookup }}</p>@endif
        </form>
    @endif
</div>
