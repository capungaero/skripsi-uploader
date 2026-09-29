<div class="max-w-4xl space-y-6">
    <div>
        <h1 class="text-2xl font-bold">Penyimpanan Cloud</h1>
        <p class="text-sm text-slate-600">File yang lolos AI dipindahkan dari server ke cloud dengan struktur <code>{{ $form['cloud__root_folder'] }}/&lt;Fakultas&gt;/&lt;NIM&gt;_&lt;Nama&gt;.pdf</code>, lalu salinan di server dihapus.</p>
    </div>
    @if ($flash)<div class="rounded-lg bg-brand-50 px-3 py-2 text-sm text-brand-800">{{ $flash }}</div>@endif
    @if ($testResult)<div class="rounded-lg bg-slate-50 px-3 py-2 font-mono text-xs">{{ $testResult }}</div>@endif

    <form wire:submit="save" class="space-y-4">
        <div class="card grid gap-4 p-5 sm:grid-cols-3">
            <div>
                <label class="label" for="prov">Provider aktif</label>
                <select id="prov" wire:model="form.cloud__provider" class="field-input">
                    <option value="none">— Nonaktif —</option>
                    @foreach ($providers as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                </select>
            </div>
            <div>
                <label class="label" for="root">Folder utama</label>
                <input id="root" wire:model="form.cloud__root_folder" class="field-input">
                @error('form.cloud__root_folder')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
            <label class="flex items-center gap-2 self-end pb-2 text-sm"><input type="checkbox" wire:model="form.cloud__share_link"> Buat link share (view-only) saat approve</label>
        </div>

        @php
            $blocks = [
                'gdrive' => [['gdrive.client_id', 'Client ID', false], ['gdrive.client_secret', 'Client secret', true], ['gdrive.parent_id', 'ID folder induk / Shared Drive (opsional)', false]],
                'onedrive' => [['onedrive.client_id', 'Application (client) ID', false], ['onedrive.client_secret', 'Client secret', true], ['onedrive.tenant', 'Tenant (common / id tenant unand)', false]],
                'dropbox' => [['dropbox.app_key', 'App key', false], ['dropbox.app_secret', 'App secret', true]],
            ];
            $notes = [
                'gdrive' => 'Buat OAuth client (Web application) di Google Cloud Console, aktifkan Drive API. Disarankan memakai Shared Drive Google Workspace Unand. File yang di-approve dikunci read-only (content restriction).',
                'onedrive' => 'Daftarkan aplikasi di Microsoft Entra ID, izin delegated Files.ReadWrite.All + offline_access. OneDrive tidak punya kunci file penuh: saat approve hanya izin edit pihak lain yang dicabut.',
                'dropbox' => 'Buat app di Dropbox App Console (scoped: files.content.write, files.content.read, sharing.write). Kunci file read-only hanya tersedia pada akun Dropbox Business.',
            ];
        @endphp

        @foreach ($blocks as $name => $inputs)
            <div class="card space-y-3 p-5">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="font-semibold">{{ $providers[$name] }}</h2>
                    @if ($status[$name]['connected'])
                        <span class="rounded-full bg-brand-100 px-2 py-0.5 text-xs text-brand-800">Terhubung</span>
                    @else
                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">Belum terhubung</span>
                    @endif
                </div>
                <p class="text-xs text-slate-500">{{ $notes[$name] }}</p>
                <div class="grid gap-3 sm:grid-cols-3">
                    @foreach ($inputs as [$key, $label, $secret])
                        <div>
                            <label class="label text-xs">{{ $label }}</label>
                            <input wire:model="form.{{ \App\Livewire\Admin\Settings\SettingsForm::field($key) }}" @if($secret) type="password" autocomplete="new-password" placeholder="{{ $this->hasSecret($key) ? '•••••• tersimpan' : '' }}" @endif class="field-input text-sm">
                        </div>
                    @endforeach
                </div>
                <p class="text-xs text-slate-500">Redirect URI yang didaftarkan: <code class="select-all">{{ $status[$name]['callback'] }}</code></p>
                <div class="flex gap-2">
                    <a href="{{ route('admin.oauth.connect', $name) }}" class="btn-secondary">{{ $status[$name]['connected'] ? 'Hubungkan ulang' : 'Hubungkan akun' }}</a>
                    @if ($status[$name]['connected'])
                        <button type="button" wire:click="disconnect('{{ $name }}')" wire:confirm="Putuskan {{ $providers[$name] }}?" class="btn-secondary text-red-600">Putuskan</button>
                    @endif
                </div>
                <p class="text-xs text-slate-400">Simpan client ID/secret terlebih dahulu sebelum menekan "Hubungkan akun".</p>
            </div>
        @endforeach

        <div class="flex gap-2">
            <button class="btn-primary">Simpan</button>
            <button type="button" wire:click="testActive" wire:loading.attr="disabled" class="btn-secondary">Tes provider aktif</button>
        </div>
    </form>
</div>
