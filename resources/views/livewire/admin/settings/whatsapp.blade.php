<div class="max-w-4xl space-y-6">

    @if ($flash)<div class="rounded-2xl bg-brand-50 px-4 py-3 text-sm text-brand-800">{{ $flash }}</div>@endif

    <form wire:submit="save" class="space-y-4">
        <div class="card space-y-4 p-5">
            <h2 class="font-semibold">Gateway HTTP</h2>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="form.wa__enabled"> Kirim notifikasi WhatsApp otomatis</label>
            <div class="grid gap-3 sm:grid-cols-6">
                <div class="sm:col-span-4">
                    <label class="label">URL endpoint</label>
                    <input wire:model="form.wa__url" class="field-input font-mono text-sm" placeholder="https://api.fonnte.com/send">
                    @error('form.wa__url')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div class="sm:col-span-1">
                    <label class="label">Method</label>
                    <select wire:model="form.wa__method" class="field-input"><option>POST</option><option>GET</option><option>PUT</option></select>
                </div>
                <div class="sm:col-span-1">
                    <label class="label">Body</label>
                    <select wire:model="form.wa__body_format" class="field-input"><option value="form">form</option><option value="json">json</option></select>
                </div>
            </div>
            <div>
                <label class="label">Header (JSON) — berisi token, disimpan terenkripsi</label>
                <textarea wire:model="form.wa__headers" rows="2" class="field-input font-mono text-xs"
                          placeholder="{{ $this->hasSecret('wa.headers') ? '•••••• tersimpan (isi untuk mengganti), mis. {&quot;Authorization&quot;: &quot;TOKEN&quot;}' : '{&quot;Authorization&quot;: &quot;TOKEN&quot;}' }}"></textarea>
                @error('form.wa__headers')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="label">Template body (JSON) dengan placeholder <code>{phone}</code> dan <code>{message}</code></label>
                <textarea wire:model="form.wa__body_template" rows="3" class="field-input font-mono text-xs"></textarea>
                @error('form.wa__body_template')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                <p class="mt-1 text-xs text-slate-500">Fonnte: <code>{"target": "{phone}", "message": "{message}"}</code> · Wablas: <code>{"phone": "{phone}", "message": "{message}"}</code>. Nomor dikirim dalam format 628xxx.</p>
            </div>
        </div>

        <div class="card space-y-4 p-5">
            <h2 class="font-semibold">Template pesan</h2>
            <p class="text-xs text-slate-500">Placeholder: <code>{nama} {nim} {judul} {alasan} {catatan} {sisa_percobaan} {link} {handle}</code>. Kosongkan untuk tidak mengirim pesan pada kejadian tersebut.</p>
            @foreach ($events as $event => $label)
                <div>
                    <label class="label">{{ $label }}</label>
                    <textarea wire:model="form.wa__tpl__{{ $event }}" rows="4" class="field-input text-sm"></textarea>
                </div>
            @endforeach
        </div>
        <button class="btn-primary">Simpan</button>
    </form>

    <div class="card flex flex-wrap items-end gap-3 p-5">
        <div><label class="label">Kirim pesan tes ke nomor</label><input wire:model="testPhone" class="field-input" placeholder="08xxxxxxxxxx"></div>
        <button wire:click="sendTest" wire:loading.attr="disabled" class="btn-secondary">Kirim tes</button>
        @error('testPhone')<p class="w-full text-xs text-red-600">{{ $message }}</p>@enderror
        @if ($testResult)<p class="w-full rounded-2xl bg-surface px-4 py-3 font-mono text-xs">{{ $testResult }}</p>@endif
    </div>
</div>
