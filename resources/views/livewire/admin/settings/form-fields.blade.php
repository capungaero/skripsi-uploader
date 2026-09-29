<div class="space-y-6">

    @if ($flash)<div class="rounded-2xl bg-brand-50 px-4 py-3 text-sm text-brand-800">{{ $flash }}</div>@endif

    <div class="card flex flex-wrap items-end gap-3 p-4">
        <div>
            <label class="label" for="cfg">Konfigurasi tahun akademik</label>
            <select id="cfg" wire:model.live="configId" class="field-input w-auto">
                @foreach ($configs as $c)<option value="{{ $c->id }}">{{ $c->academic_year }}{{ $c->is_active ? ' (aktif)' : '' }}</option>@endforeach
            </select>
        </div>
        @if ($current && ! $current->is_active)
            <button wire:click="activate" wire:confirm="Aktifkan form {{ $current->academic_year }} untuk mahasiswa?" class="btn-primary">Aktifkan</button>
        @endif
        <div class="ml-auto flex items-end gap-2">
            <div>
                <label class="label" for="clone">Salin ke tahun baru</label>
                <input id="clone" wire:model="cloneYear" placeholder="2027/2028" class="field-input w-32">
            </div>
            <button wire:click="cloneConfig" class="btn-secondary">Salin</button>
        </div>
        @error('cloneYear')<p class="w-full text-xs text-red-600">{{ $message }}</p>@enderror
    </div>

    <form wire:submit="save" class="card overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-surface text-left text-xs text-slate-500">
            <tr><th class="px-3 py-2">Urut</th><th class="px-3 py-2">Key / Tipe</th><th class="px-3 py-2">Label</th><th class="px-3 py-2">Wajib</th><th class="px-3 py-2">Aktif</th><th class="px-3 py-2">Detail</th><th></th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100 align-top">
            @foreach ($fields as $id => $f)
                <tr wire:key="field-{{ $id }}">
                    <td class="px-3 py-2"><input type="number" wire:model="fields.{{ $id }}.sort" class="field-input w-16"></td>
                    <td class="px-3 py-2"><code class="text-xs">{{ $f['key'] }}</code><p class="text-xs text-slate-500">{{ $f['type'] }}{{ $f['is_system'] ? ' · sistem' : '' }}</p></td>
                    <td class="px-3 py-2"><input wire:model="fields.{{ $id }}.label" class="field-input min-w-40">
                        @error("fields.$id.label")<p class="text-xs text-red-600">{{ $message }}</p>@enderror</td>
                    <td class="px-3 py-2"><input type="checkbox" wire:model="fields.{{ $id }}.required" @disabled($f['is_system'])></td>
                    <td class="px-3 py-2"><input type="checkbox" wire:model="fields.{{ $id }}.active" @disabled($f['is_system'])></td>
                    <td class="space-y-1 px-3 py-2">
                        <input wire:model="fields.{{ $id }}.placeholder" placeholder="Placeholder" class="field-input text-xs">
                        <input wire:model="fields.{{ $id }}.help" placeholder="Teks bantuan" class="field-input text-xs">
                        @if (in_array($f['type'], ['text', 'tel', 'email', 'textarea', 'number']))
                            <div class="flex gap-1">
                                <input wire:model="fields.{{ $id }}.pattern" placeholder="Regex, mis. ^[0-9]+$" class="field-input font-mono text-xs">
                                <input type="number" wire:model="fields.{{ $id }}.max_length" placeholder="Maks" class="field-input w-20 text-xs">
                            </div>
                            @error("fields.$id.pattern")<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                        @endif
                        @if ($f['type'] === 'select')
                            <textarea wire:model="fields.{{ $id }}.options" rows="3" placeholder="Satu pilihan per baris" class="field-input text-xs"></textarea>
                        @endif
                    </td>
                    <td class="px-3 py-2">
                        @unless ($f['is_system'])
                            <button type="button" wire:click="deleteField({{ $id }})" wire:confirm="Hapus field {{ $f['key'] }}?" class="text-xs text-red-600 underline">Hapus</button>
                        @endunless
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
        <div class="border-t border-slate-100 p-4"><button class="btn-primary">Simpan perubahan</button></div>
    </form>

    <div class="card p-4">
        <h2 class="mb-3 font-semibold">Tambah field</h2>
        <div class="flex flex-wrap items-end gap-3">
            <div><label class="label">Key</label><input wire:model="new.key" placeholder="nomor_hp_ortu" class="field-input font-mono"></div>
            <div><label class="label">Label</label><input wire:model="new.label" class="field-input"></div>
            <div><label class="label">Tipe</label>
                <select wire:model="new.type" class="field-input">
                    @foreach (['text', 'email', 'tel', 'number', 'textarea', 'select'] as $t)<option>{{ $t }}</option>@endforeach
                </select></div>
            <button wire:click="addField" class="btn-secondary">Tambah</button>
        </div>
        @error('new.key')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        @error('new.label')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        <p class="mt-2 text-xs text-slate-500">Nilai field tambahan disimpan di data unggahan dan dapat dipetakan ke metadata DSpace.</p>
    </div>
</div>
