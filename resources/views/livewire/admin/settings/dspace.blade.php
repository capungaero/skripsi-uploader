<div class="max-w-3xl space-y-6">
    <div>

        <p class="text-sm text-slate-600">Item disetor lewat REST API DSpace 7+ langsung sebagai item terbit (arsip) di koleksi fakultas, dengan PDF di bundle ORIGINAL. Akun harus administrator (atau admin koleksi) DSpace.</p>
    </div>
    @if ($flash)<div class="rounded-2xl bg-brand-50 px-4 py-3 text-sm text-brand-800">{{ $flash }}</div>@endif

    <form wire:submit="save" class="card space-y-4 p-5">
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="form.dspace__enabled"> Aktifkan integrasi DSpace</label>
        <div>
            <label class="label">URL server REST (diakhiri /server)</label>
            <input wire:model="form.dspace__base_url" class="field-input font-mono text-sm" placeholder="https://repository.unand.ac.id/server">
            @error('form.dspace__base_url')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>
        <div class="grid gap-3 sm:grid-cols-2">
            <div><label class="label">Email akun DSpace</label><input wire:model="form.dspace__email" class="field-input" autocomplete="off"></div>
            <div><label class="label">Password</label><input type="password" wire:model="form.dspace__password" autocomplete="new-password" class="field-input"
                    placeholder="{{ $this->hasSecret('dspace.password') ? '•••••• tersimpan' : '' }}"></div>
        </div>
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="form.dspace__auto_deposit"> Setor otomatis ke DSpace setelah admin approve</label>
        <div>
            <label class="label">Pemetaan field form → metadata DSpace (<code>key_form = dc.field</code>)</label>
            <textarea wire:model="mapText" rows="7" class="field-input font-mono text-xs"></textarea>
            <p class="mt-1 text-xs text-slate-500">Key tersedia: nim, nama, judul, fakultas, prodi, dan semua key field tambahan (mis. pembimbing, abstrak, tahun_akademik, email). Nilai pembimbing dipisah ";" menjadi beberapa nilai.</p>
        </div>
        <div>
            <label class="label">Metadata tetap (<code>dc.field = nilai</code>)</label>
            <textarea wire:model="fixedText" rows="4" class="field-input font-mono text-xs"></textarea>
            <p class="mt-1 text-xs text-slate-500"><code>dc.date.issued</code> otomatis diisi tahun unggah jika tidak dipetakan.</p>
        </div>
        <div class="flex gap-2">
            <button class="btn-primary">Simpan</button>
            <button type="button" wire:click="testLogin" wire:loading.attr="disabled" class="btn-secondary">Tes login</button>
        </div>
        @if ($testResult)<p class="rounded-2xl bg-surface px-4 py-3 font-mono text-xs">{{ $testResult }}</p>@endif
        <p class="text-xs text-slate-500">UUID koleksi tujuan diatur per fakultas di menu <a href="{{ route('admin.settings.faculties') }}" wire:navigate class="underline">Fakultas & Prodi</a>.</p>
    </form>
</div>
