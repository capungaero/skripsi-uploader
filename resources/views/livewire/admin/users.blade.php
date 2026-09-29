<div class="space-y-6">
    <h1 class="text-2xl font-bold">Pengguna Admin</h1>
    @if ($flash)<div class="rounded-lg bg-brand-50 px-3 py-2 text-sm text-brand-800">{{ $flash }}</div>@endif

    <div class="card overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-left text-xs text-slate-500">
            <tr><th class="px-4 py-2">Nama</th><th class="px-4 py-2">Email</th><th class="px-4 py-2">Peran</th><th class="px-4 py-2">Status</th><th class="px-4 py-2">Login terakhir</th><th></th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
            @foreach ($users as $u)
                <tr>
                    <td class="px-4 py-2">{{ $u->name }}</td>
                    <td class="px-4 py-2">{{ $u->email }}</td>
                    <td class="px-4 py-2">{{ \App\Models\User::ROLES[$u->role] ?? $u->role }}</td>
                    <td class="px-4 py-2">{{ $u->is_active ? 'Aktif' : 'Nonaktif' }}</td>
                    <td class="px-4 py-2 text-xs text-slate-500">{{ $u->last_login_at?->format('d/m/Y H:i') ?? '-' }}</td>
                    <td class="px-4 py-2"><button wire:click="edit({{ $u->id }})" class="text-xs text-brand-700 underline">Ubah</button></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    <form wire:submit="save" class="card max-w-2xl space-y-3 p-5">
        <h2 class="font-semibold">{{ $editingId ? 'Ubah pengguna' : 'Tambah pengguna' }}</h2>
        <div class="grid gap-3 sm:grid-cols-2">
            <div><label class="label">Nama</label><input wire:model="user.name" class="field-input">@error('user.name')<p class="text-xs text-red-600">{{ $message }}</p>@enderror</div>
            <div><label class="label">Email</label><input type="email" wire:model="user.email" class="field-input">@error('user.email')<p class="text-xs text-red-600">{{ $message }}</p>@enderror</div>
            <div><label class="label">Peran</label>
                <select wire:model="user.role" class="field-input">@foreach (\App\Models\User::ROLES as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select>
                <p class="mt-1 text-xs text-slate-500">Verifikator: approve/tolak saja. Superadmin: juga pengaturan.</p></div>
            <div><label class="label">Password {{ $editingId ? '(kosongkan jika tidak diganti)' : '' }}</label>
                <input type="password" wire:model="user.password" autocomplete="new-password" class="field-input">
                @error('user.password')<p class="text-xs text-red-600">{{ $message }}</p>@enderror</div>
        </div>
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="user.is_active"> Aktif</label>
        <div class="flex gap-2">
            <button class="btn-primary">Simpan</button>
            @if ($editingId)<button type="button" wire:click="cancel" class="btn-secondary">Batal</button>@endif
        </div>
    </form>
</div>
