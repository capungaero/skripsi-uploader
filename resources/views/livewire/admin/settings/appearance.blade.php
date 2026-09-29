<div class="max-w-3xl space-y-6">

    @if ($flash)<div class="rounded-2xl bg-brand-50 px-4 py-3 text-sm text-brand-800">{{ $flash }}</div>@endif

    <form wire:submit="save" class="card space-y-4 p-5">
        <div>
            <label class="label" for="title">Judul halaman</label>
            <input id="title" wire:model="form.appearance__title" class="field-input">
            @error('form.appearance__title')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="label" for="desc">Deskripsi</label>
            <textarea id="desc" wire:model="form.appearance__description" rows="2" class="field-input"></textarea>
        </div>
        <div>
            <label class="label" for="instr">Instruksi (satu baris per poin)</label>
            <textarea id="instr" wire:model="form.appearance__instructions" rows="6" class="field-input"></textarea>
        </div>
        <div>
            <span class="label">Banner gambar (JPG/PNG/WebP, maks. 3 MB, rasio lebar ±4:1)</span>
            @if ($banner)
                <img src="{{ $banner->temporaryUrl() }}" alt="" class="mb-2 h-32 w-full rounded-lg object-cover">
            @elseif ($bannerUrl)
                <img src="{{ $bannerUrl }}" alt="" class="mb-2 h-32 w-full rounded-lg object-cover">
                <button type="button" wire:click="removeBanner" class="mb-2 text-xs text-red-600 underline">Hapus banner</button>
            @endif
            <input type="file" wire:model="banner" accept="image/*" class="block text-sm">
            @error('banner')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="label" for="maxmb">Batas ukuran PDF (MB)</label>
                <input id="maxmb" type="number" wire:model="form.upload__max_mb" class="field-input">
                <p class="mt-1 text-xs text-slate-500">Pastikan upload_max_filesize PHP & client_max_body_size Nginx lebih besar.</p>
                @error('form.upload__max_mb')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="label" for="maxatt">Batas percobaan unggah per NIM</label>
                <input id="maxatt" type="number" wire:model="form.upload__max_attempts" class="field-input">
                @error('form.upload__max_attempts')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
        </div>
        <div class="flex gap-2">
            <button class="btn-primary">Simpan</button>
            <a href="{{ route('client') }}" target="_blank" class="btn-secondary">Lihat halaman mahasiswa</a>
        </div>
    </form>
</div>
