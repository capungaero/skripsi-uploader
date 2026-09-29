<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-end gap-3">

        <div class="flex gap-2">
            @if ($selected)
                <button wire:click="depositSelected" wire:confirm="Setor {{ count($selected) }} unggahan terpilih ke DSpace?" class="btn-primary">
                    Setor {{ count($selected) }} ke DSpace
                </button>
            @endif
            <a href="{{ $exportUrl }}" class="btn-secondary">Export CSV</a>
        </div>
    </div>

    @if ($flash)<div class="rounded-2xl bg-brand-50 px-4 py-3 text-sm text-brand-800">{{ $flash }}</div>@endif

    <div class="card grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
        <input type="search" wire:model.live.debounce.400ms="search" placeholder="Cari NIM, nama, atau fakultas…" class="field-input">
        <select wire:model.live="status" class="field-input">
            <option value="">Semua status</option>
            @foreach ($statuses as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
        </select>
        <select wire:model.live="faculty" class="field-input">
            <option value="">Semua fakultas</option>
            @foreach ($faculties as $f)<option value="{{ $f->id }}">{{ $f->name }}</option>@endforeach
        </select>
        <select wire:model.live="year" class="field-input">
            <option value="">Semua tahun</option>
            @foreach ($years as $y)<option value="{{ $y }}">{{ $y }}</option>@endforeach
        </select>
    </div>

    <div class="card overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-surface text-left text-xs text-slate-500">
            <tr>
                <th class="w-8 px-3 py-2"></th>
                <th class="px-3 py-2">NIM / Nama</th>
                <th class="px-3 py-2">Fakultas / Prodi</th>
                <th class="px-3 py-2">Judul</th>
                <th class="px-3 py-2">Skor</th>
                <th class="px-3 py-2">Status</th>
                <th class="px-3 py-2">Diunggah</th>
            </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
            @forelse ($rows as $s)
                <tr wire:key="row-{{ $s->id }}" class="hover:bg-slate-50">
                    <td class="px-3 py-2">
                        @if (in_array($s->status, ['approved', 'deposit_failed']))
                            <input type="checkbox" wire:model.live="selected" value="{{ $s->id }}" aria-label="Pilih">
                        @endif
                    </td>
                    <td class="px-3 py-2">
                        <a href="{{ route('admin.submissions.show', $s) }}" wire:navigate class="font-medium text-brand-700 hover:underline">{{ $s->nim }}</a>
                        <p class="text-xs text-slate-600">{{ $s->nama }}</p>
                    </td>
                    <td class="px-3 py-2 text-xs">{{ $s->faculty?->name }}<br><span class="text-slate-500">{{ $s->studyProgram?->name }}</span></td>
                    <td class="max-w-xs px-3 py-2 text-xs"><span class="line-clamp-2">{{ $s->judul }}</span></td>
                    <td class="px-3 py-2 tabular-nums">{{ $s->ai_score ?? '-' }}</td>
                    <td class="px-3 py-2">@include('livewire.admin.partials.status-badge', ['submission' => $s])</td>
                    <td class="whitespace-nowrap px-3 py-2 text-xs text-slate-500">{{ $s->created_at->format('d/m/Y H:i') }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-3 py-10 text-center text-slate-500">Tidak ada data.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{ $rows->links() }}
</div>
