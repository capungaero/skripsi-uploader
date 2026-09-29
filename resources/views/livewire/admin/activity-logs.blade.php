<div class="space-y-4">
    <h1 class="text-2xl font-bold">Log Aktivitas</h1>

    <div class="card grid gap-3 p-4 sm:grid-cols-3">
        <select wire:model.live="actor" class="field-input">
            <option value="">Semua aktor</option><option value="student">Mahasiswa</option><option value="admin">Admin</option><option value="system">Sistem</option>
        </select>
        <select wire:model.live="action" class="field-input">
            <option value="">Semua aksi</option>
            @foreach ($prefixes as $p)<option value="{{ $p }}.">{{ $p }}.*</option>@endforeach
        </select>
        <input type="search" wire:model.live.debounce.400ms="search" placeholder="Cari email admin atau NIM…" class="field-input">
    </div>

    <div class="card overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-left text-xs text-slate-500">
            <tr><th class="px-3 py-2">Waktu</th><th class="px-3 py-2">Aktor</th><th class="px-3 py-2">Aksi</th><th class="px-3 py-2">Unggahan</th><th class="px-3 py-2">Detail</th><th class="px-3 py-2">IP</th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100 align-top">
            @forelse ($logs as $log)
                <tr>
                    <td class="whitespace-nowrap px-3 py-2 text-xs">{{ $log->created_at->format('d/m/Y H:i:s') }}</td>
                    <td class="px-3 py-2 text-xs"><span class="text-slate-400">{{ $log->actor_type }}</span><br>{{ $log->actor_label }}</td>
                    <td class="px-3 py-2 font-mono text-xs">{{ $log->action }}</td>
                    <td class="px-3 py-2 text-xs">
                        @if ($log->submission)<a href="{{ route('admin.submissions.show', $log->submission_id) }}" wire:navigate class="text-brand-700 hover:underline">{{ $log->submission->nim }}</a>@endif
                    </td>
                    <td class="max-w-md px-3 py-2"><code class="block truncate text-xs text-slate-500" title="{{ json_encode($log->meta, JSON_UNESCAPED_UNICODE) }}">{{ $log->meta ? json_encode($log->meta, JSON_UNESCAPED_UNICODE) : '' }}</code></td>
                    <td class="px-3 py-2 text-xs text-slate-500">{{ $log->ip }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-3 py-10 text-center text-slate-500">Belum ada log.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $logs->links() }}
</div>
