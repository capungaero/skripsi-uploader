<div class="space-y-6">

    @if ($flash)<div class="rounded-2xl bg-brand-50 px-4 py-3 text-sm text-brand-800">{{ $flash }}</div>@endif

    <form wire:submit="save" class="card max-w-3xl space-y-4 p-5">
        <h2 class="font-semibold">Koneksi AI (format OpenAI-compatible)</h2>
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="form.ai__enabled"> Aktifkan pengecekan AI otomatis
            <span class="text-xs text-slate-500">(jika mati, semua unggahan langsung masuk antrean verifikasi manual)</span></label>
        <div>
            <label class="label" for="base">Base URL</label>
            <input id="base" wire:model="form.ai__base_url" class="field-input font-mono text-sm" placeholder="https://generativelanguage.googleapis.com/v1beta/openai">
            <p class="mt-1 text-xs text-slate-500">Contoh: Gemini <code>…/v1beta/openai</code>, OpenRouter <code>https://openrouter.ai/api/v1</code>, Xiaomi MiMo, vLLM. Endpoint <code>/chat/completions</code> ditambahkan otomatis.</p>
            @error('form.ai__base_url')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="label" for="key">Token API</label>
                <input id="key" type="password" wire:model="form.ai__api_key" autocomplete="new-password" class="field-input"
                       placeholder="{{ $this->hasSecret('ai.api_key') ? '•••••• tersimpan (isi untuk mengganti)' : 'Belum diisi' }}">
            </div>
            <div>
                <label class="label" for="model">Model (harus mendukung gambar/vision)</label>
                <input id="model" wire:model="form.ai__model" class="field-input font-mono text-sm" placeholder="gemini-2.5-flash">
            </div>
        </div>
        <div class="grid gap-4 sm:grid-cols-4">
            <div><label class="label">Maks. token jawaban</label><input type="number" wire:model="form.ai__max_tokens" class="field-input"></div>
            <div><label class="label">Timeout (detik)</label><input type="number" wire:model="form.ai__timeout" class="field-input"></div>
            <div><label class="label">Maks. gambar halaman</label><input type="number" wire:model="form.ai__max_images" class="field-input"></div>
            <div><label class="label">Maks. karakter teks</label><input type="number" wire:model="form.ai__max_text_chars" class="field-input"></div>
        </div>
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="form.ai__json_mode"> Kirim <code>response_format: json_object</code>
            <span class="text-xs text-slate-500">(matikan jika provider menolak parameter ini)</span></label>
        <div class="flex flex-wrap items-center gap-2">
            <button class="btn-primary">Simpan</button>
            <button type="button" wire:click="testConnection" wire:loading.attr="disabled" class="btn-secondary">
                <span wire:loading.remove wire:target="testConnection">Tes koneksi</span><span wire:loading wire:target="testConnection">Menguji…</span>
            </button>
        </div>
        @if ($testResult)<p class="rounded-2xl bg-surface px-4 py-3 font-mono text-xs">{{ $testResult }}</p>@endif
    </form>

    <form wire:submit="saveCriteria" class="space-y-3">
        <h2 class="font-semibold">Kriteria penilaian</h2>
        <p class="text-sm text-slate-600">Setiap kriteria diberi skor 0–100. Unggahan ditolak jika ada kriteria <em>wajib</em> di bawah skor minimum; alasan dikirim ke mahasiswa.</p>
        @foreach ($criteria as $id => $c)
            <div wire:key="crit-{{ $id }}" class="card grid gap-3 p-4 md:grid-cols-12">
                <div class="md:col-span-4 space-y-2">
                    <input wire:model="criteria.{{ $id }}.label" class="field-input font-medium">
                    <code class="text-xs text-slate-500">{{ $c['key'] }}</code>
                    <div class="flex flex-wrap gap-3 text-sm">
                        <label class="flex items-center gap-1"><input type="checkbox" wire:model="criteria.{{ $id }}.active"> Aktif</label>
                        <label class="flex items-center gap-1"><input type="checkbox" wire:model="criteria.{{ $id }}.required"> Wajib</label>
                        <label class="flex items-center gap-1"><input type="checkbox" wire:model="criteria.{{ $id }}.needs_image"> Kirim gambar</label>
                    </div>
                    <div class="flex gap-2">
                        <label class="text-xs">Skor min.<input type="number" wire:model="criteria.{{ $id }}.min_score" class="field-input w-20"></label>
                        <label class="text-xs">Urut<input type="number" wire:model="criteria.{{ $id }}.sort" class="field-input w-20"></label>
                    </div>
                </div>
                <div class="md:col-span-8 space-y-2">
                    <textarea wire:model="criteria.{{ $id }}.instruction" rows="3" class="field-input text-sm" aria-label="Instruksi"></textarea>
                    <input wire:model="criteria.{{ $id }}.page_keywords" class="field-input text-xs" placeholder="Kata kunci halaman, pisahkan koma: PENGESAHAN, PERSETUJUAN">
                    <button type="button" wire:click="deleteCriterion({{ $id }})" wire:confirm="Hapus kriteria {{ $c['label'] }}?" class="text-xs text-red-600 underline">Hapus kriteria</button>
                </div>
            </div>
        @endforeach
        @error('criteria.*')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
        <button class="btn-primary">Simpan kriteria</button>
    </form>

    <div class="card flex flex-wrap items-end gap-3 p-4">
        <div><label class="label">Key</label><input wire:model="newCriterion.key" placeholder="abstrak_ada" class="field-input font-mono"></div>
        <div class="flex-1"><label class="label">Nama kriteria baru</label><input wire:model="newCriterion.label" class="field-input"></div>
        <button wire:click="addCriterion" class="btn-secondary">Tambah kriteria</button>
        @error('newCriterion.key')<p class="w-full text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
</div>
