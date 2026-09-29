<div class="space-y-6">
    <div>

        <p class="text-sm text-slate-600">Nama fakultas dipakai sebagai subfolder cloud. UUID koleksi DSpace menentukan koleksi tujuan item yang disetor.</p>
    </div>
    @if ($flash)<div class="rounded-2xl bg-brand-50 px-4 py-3 text-sm text-brand-800">{{ $flash }}</div>@endif

    <form wire:submit="save" class="space-y-3">
        @foreach ($rows as $id => $row)
            <div wire:key="fac-{{ $id }}" class="card p-4" x-data="{ open: false }">
                <div class="grid items-start gap-3 md:grid-cols-12">
                    <input type="number" wire:model="rows.{{ $id }}.sort" class="field-input md:col-span-1" aria-label="Urutan">
                    <div class="md:col-span-4">
                        <input wire:model="rows.{{ $id }}.name" class="field-input" aria-label="Nama fakultas">
                        <p class="mt-1 text-xs text-slate-500">{{ $row['code'] }}</p>
                    </div>
                    <div class="md:col-span-5">
                        <input wire:model="rows.{{ $id }}.dspace_collection_uuid" placeholder="UUID koleksi DSpace" class="field-input font-mono text-xs">
                        @error("rows.$id.dspace_collection_uuid")<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <label class="flex items-center gap-2 text-sm md:col-span-1"><input type="checkbox" wire:model="rows.{{ $id }}.is_active"> Aktif</label>
                    <button type="button" @click="open = !open" class="text-left text-xs text-brand-700 underline md:col-span-1">
                        Prodi ({{ count($programs[$id] ?? []) }})
                    </button>
                </div>
                <div x-show="open" x-cloak class="mt-3 border-t border-slate-100 pt-3">
                    <div class="flex flex-wrap gap-2">
                        @foreach ($programs[$id] ?? [] as $p)
                            <button type="button" wire:click="toggleProgram({{ $p->id }})" title="Klik untuk aktif/nonaktif"
                                    class="rounded-full border px-3 py-1 text-xs {{ $p->is_active ? 'border-brand-600 bg-brand-50 text-brand-800' : 'border-slate-300 text-slate-400 line-through' }}">{{ $p->name }}</button>
                        @endforeach
                    </div>
                    <div class="mt-2 flex gap-2">
                        <input wire:model="newProgram.{{ $id }}" placeholder="Program studi baru" class="field-input max-w-xs text-sm">
                        <button type="button" wire:click="addProgram({{ $id }})" class="btn-secondary">Tambah</button>
                    </div>
                </div>
            </div>
        @endforeach
        <button class="btn-primary">Simpan fakultas</button>
    </form>

    <div class="card flex flex-wrap items-end gap-3 p-4">
        <div><label class="label">Kode</label><input wire:model="newFaculty.code" class="field-input w-32"></div>
        <div class="flex-1"><label class="label">Nama fakultas baru</label><input wire:model="newFaculty.name" class="field-input"></div>
        <button wire:click="addFaculty" class="btn-secondary">Tambah fakultas</button>
        @error('newFaculty.code')<p class="w-full text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
</div>
