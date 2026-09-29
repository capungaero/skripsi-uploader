<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-bold">Dashboard</h1>
        <select wire:model.live="year" class="field-input w-auto">
            <option value="">Semua tahun akademik</option>
            @foreach ($years as $y)<option value="{{ $y }}">{{ $y }}</option>@endforeach
        </select>
    </div>

    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-7">
        @foreach ($stats as [$label, $value, $color, $filter])
            <a @if($filter) href="{{ route('admin.submissions', ['status' => $filter, 'year' => $year ?: null]) }}" wire:navigate @endif
               class="card block p-4 {{ $filter ? 'hover:border-brand-600' : '' }}">
                <p class="text-xs text-slate-500">{{ $label }}</p>
                <p class="mt-1 text-2xl font-bold tabular-nums {{ $color }}">{{ number_format($value, 0, ',', '.') }}</p>
            </a>
        @endforeach
    </div>

    <div class="grid gap-6 lg:grid-cols-5">
        <div class="card overflow-x-auto lg:col-span-3">
            <h2 class="border-b border-slate-100 px-4 py-3 font-semibold">Rekap per fakultas</h2>
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs text-slate-500">
                <tr><th class="px-4 py-2">Fakultas</th><th class="px-2 py-2 text-right">Total</th><th class="px-2 py-2 text-right">Antrean</th><th class="px-2 py-2 text-right">Disetujui</th><th class="px-4 py-2 text-right">Ditolak</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                @foreach ($faculties as $f)
                    @php $row = $perFaculty[$f->id] ?? null; @endphp
                    <tr>
                        <td class="px-4 py-2"><a href="{{ route('admin.submissions', ['faculty' => $f->id, 'year' => $year ?: null]) }}" wire:navigate class="hover:underline">{{ $f->name }}</a></td>
                        <td class="px-2 py-2 text-right tabular-nums">{{ $row->total ?? 0 }}</td>
                        <td class="px-2 py-2 text-right tabular-nums text-blue-700">{{ $row->queue ?? 0 }}</td>
                        <td class="px-2 py-2 text-right tabular-nums text-brand-700">{{ $row->approved ?? 0 }}</td>
                        <td class="px-4 py-2 text-right tabular-nums text-red-600">{{ $row->rejected ?? 0 }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <div class="card lg:col-span-2">
            <h2 class="border-b border-slate-100 px-4 py-3 font-semibold">Antrean verifikasi terlama</h2>
            <ul class="divide-y divide-slate-100 text-sm">
                @forelse ($queue as $s)
                    <li>
                        <a href="{{ route('admin.submissions.show', $s) }}" wire:navigate class="block px-4 py-2 hover:bg-slate-50">
                            <p class="font-medium">{{ $s->nim }} · {{ $s->nama }}</p>
                            <p class="text-xs text-slate-500">{{ $s->faculty?->name }} · {{ $s->created_at->diffForHumans() }} · skor {{ $s->ai_score ?? '-' }}</p>
                        </a>
                    </li>
                @empty
                    <li class="px-4 py-6 text-center text-slate-500">Tidak ada antrean.</li>
                @endforelse
            </ul>
        </div>
    </div>
</div>
