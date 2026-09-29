<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <a href="{{ route('admin.submissions') }}" wire:navigate class="text-sm text-slate-500 hover:underline">← Daftar unggahan</a>
            <h1 class="mt-1 text-2xl font-semibold text-slate-800">{{ $s->nim }} · {{ $s->nama }}</h1>
            <div class="mt-1 flex flex-wrap items-center gap-2 text-sm text-slate-600">
                @include('livewire.admin.partials.status-badge', ['submission' => $s])
                <span>Percobaan terpakai {{ $maxAttempts - $remaining }}/{{ $maxAttempts }}</span>
            </div>
        </div>
        @if ($hasFile)
            <a href="{{ route('admin.submissions.file', $s) }}" target="_blank" class="btn-secondary">Buka PDF</a>
        @endif
    </div>

    @if ($flash)<div class="rounded-2xl bg-brand-50 px-4 py-3 text-sm text-brand-800">{{ $flash }}</div>@endif
    @error('status')<div class="rounded-2xl bg-accent-100 px-4 py-3 text-sm text-red-700">{{ $message }}</div>@enderror
    @if ($s->last_error)
        <div class="rounded-2xl bg-orange-50 px-4 py-3 text-sm text-orange-800"><span class="font-semibold">Catatan sistem:</span> {{ $s->last_error }}</div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            {{-- Metadata --}}
            <section class="card p-5">
                <h2 class="mb-3 font-semibold">Data mahasiswa</h2>
                <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                    <div><dt class="text-xs text-slate-500">Fakultas</dt><dd>{{ $s->faculty?->name }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Program studi</dt><dd>{{ $s->studyProgram?->name }}</dd></div>
                    <div><dt class="text-xs text-slate-500">No. WhatsApp</dt><dd>{{ $s->no_wa }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Periode unggah</dt><dd>{{ $s->academic_year }}</dd></div>
                    <div class="sm:col-span-2"><dt class="text-xs text-slate-500">Judul</dt><dd class="font-medium">{{ $s->judul }}</dd></div>
                    @foreach ($extra as $label => $value)
                        <div class="{{ strlen((string) $value) > 80 ? 'sm:col-span-2' : '' }}"><dt class="text-xs text-slate-500">{{ $label }}</dt><dd class="whitespace-pre-line">{{ $value }}</dd></div>
                    @endforeach
                    <div><dt class="text-xs text-slate-500">File</dt><dd>{{ $s->original_filename }} · {{ number_format($s->file_size / 1048576, 1) }} MB · {{ $s->page_count ?? '?' }} hlm</dd></div>
                    <div><dt class="text-xs text-slate-500">Diunggah</dt><dd>{{ $s->created_at->format('d/m/Y H:i') }}</dd></div>
                    <div class="sm:col-span-2"><dt class="text-xs text-slate-500">Cloud</dt>
                        <dd>
                            @if ($s->cloud_file_id)
                                {{ \App\Services\Cloud\CloudStorageManager::PROVIDERS[$s->cloud_provider] ?? $s->cloud_provider }}: {{ $s->cloud_path }}
                                {{ $s->cloud_locked ? '· read-only' : '' }}
                                @if ($s->share_url) · <a href="{{ $s->share_url }}" target="_blank" rel="noopener" class="text-brand-700 underline">link share</a>@endif
                            @elseif (\App\Services\Staging::exists($s))
                                Masih di staging server
                                <button wire:click="retryCloud" class="ml-2 text-xs text-brand-700 underline">Kirim ulang ke cloud</button>
                            @else
                                -
                            @endif
                        </dd>
                    </div>
                    @if ($s->dspace_item_uuid)
                        <div class="sm:col-span-2"><dt class="text-xs text-slate-500">DSpace</dt>
                            <dd>{{ $s->dspace_handle ?? $s->dspace_item_uuid }} @if ($handleUrl)· <a href="{{ $handleUrl }}" target="_blank" rel="noopener" class="text-brand-700 underline">buka</a>@endif</dd>
                        </div>
                    @endif
                </dl>
            </section>

            {{-- AI checks --}}
            <section class="card p-5">
                <h2 class="mb-3 font-semibold">Hasil pengecekan AI</h2>
                @forelse ($s->checks as $check)
                    <div class="mb-4 last:mb-0">
                        <p class="mb-2 text-xs text-slate-500">
                            {{ $check->created_at->format('d/m/Y H:i') }} · {{ $check->model ?? '-' }}
                            @if ($check->duration_ms) · {{ number_format($check->duration_ms / 1000, 1) }} dtk @endif
                            @unless ($check->error) · skor rata-rata <strong>{{ $check->overall_score }}</strong> · {{ $check->passed ? 'LULUS' : 'TIDAK LULUS' }} @endunless
                        </p>
                        @if ($check->error)
                            <p class="rounded bg-orange-50 px-3 py-2 text-xs text-orange-800">Error: {{ $check->error }}</p>
                        @else
                            <table class="w-full text-sm">
                                <tbody class="divide-y divide-slate-100">
                                @foreach ($check->criteria ?? [] as $c)
                                    <tr>
                                        <td class="py-1.5 pr-2 font-bold {{ $c['passed'] ? 'text-brand-600' : 'text-red-600' }}">{{ $c['passed'] ? '✓' : '✗' }}</td>
                                        <td class="py-1.5 pr-3">
                                            <p class="font-medium">{{ $c['label'] }} @unless ($c['required'])<span class="text-xs font-normal text-slate-400">(opsional)</span>@endunless</p>
                                            <p class="text-xs text-slate-500">{{ $c['reason'] }}</p>
                                        </td>
                                        <td class="whitespace-nowrap py-1.5 text-right tabular-nums">{{ $c['score'] }} <span class="text-xs text-slate-400">/ min {{ $c['min_score'] }}</span></td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        @endif
                    </div>
                @empty
                    <p class="text-sm text-slate-500">Belum ada hasil.</p>
                @endforelse
            </section>
        </div>

        <div class="space-y-6">
            {{-- Actions --}}
            <section class="card space-y-3 p-5">
                <h2 class="font-semibold">Verifikasi</h2>
                @if (in_array($s->status, ['ai_accepted', 'approved', 'deposit_failed']))
                    <div>
                        <label for="note" class="label">Catatan admin</label>
                        <textarea id="note" wire:model="note" rows="4" class="field-input" placeholder="Wajib diisi jika menolak. Dikirim ke mahasiswa."></textarea>
                        @error('note')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                @endif
                <div class="flex flex-wrap gap-2">
                    @if ($s->status === 'ai_accepted')
                        <button wire:click="approve" wire:loading.attr="disabled" class="btn-primary">Approve</button>
                    @endif
                    @if (in_array($s->status, ['ai_accepted', 'approved', 'deposit_failed']))
                        <button wire:click="reject" wire:loading.attr="disabled" wire:confirm="Tolak unggahan ini? File di cloud akan dihapus." class="btn-danger">Tolak</button>
                    @endif
                    @if (in_array($s->status, ['approved', 'deposit_failed']))
                        <button wire:click="deposit" wire:loading.attr="disabled" class="btn-secondary">Simpan ke DSpace</button>
                    @endif
                    @if ($s->status === 'ai_error')
                        <button wire:click="recheck" wire:loading.attr="disabled" class="btn-secondary">Cek ulang AI</button>
                    @endif
                </div>
                @if ($s->verifier)
                    <p class="text-xs text-slate-500">Diverifikasi oleh {{ $s->verifier->name }} · {{ $s->verified_at?->format('d/m/Y H:i') }}</p>
                @endif
                @if ($s->admin_note && ! in_array($s->status, ['ai_accepted']))
                    <p class="text-sm"><span class="text-xs text-slate-500">Catatan:</span> {{ $s->admin_note }}</p>
                @endif
                @if ($s->rejection_reasons)
                    <div class="text-sm"><p class="text-xs text-slate-500">Alasan penolakan terkirim:</p>
                        <ul class="list-disc pl-5">@foreach ($s->rejection_reasons as $r)<li>{{ $r }}</li>@endforeach</ul></div>
                @endif
                @if ($remaining < $maxAttempts)
                    <button wire:click="resetAttempts" wire:confirm="Reset jatah percobaan untuk NIM {{ $s->nim }}?" class="text-xs text-brand-700 underline">Reset jatah percobaan NIM ini</button>
                @endif
            </section>

            @if ($history->isNotEmpty())
                <section class="card p-5">
                    <h2 class="mb-2 font-semibold">Unggahan lain NIM ini</h2>
                    <ul class="space-y-1 text-sm">
                        @foreach ($history as $h)
                            <li><a href="{{ route('admin.submissions.show', $h) }}" wire:navigate class="hover:underline">{{ $h->created_at->format('d/m/Y') }}</a> · @include('livewire.admin.partials.status-badge', ['submission' => $h])</li>
                        @endforeach
                    </ul>
                </section>
            @endif

            <section class="card p-5">
                <h2 class="mb-2 font-semibold">Riwayat aktivitas</h2>
                <ol class="space-y-2 text-xs">
                    @foreach ($s->logs as $log)
                        <li>
                            <p><span class="font-medium">{{ $log->action }}</span> · {{ $log->actor_label ?? $log->actor_type }}</p>
                            <p class="text-slate-500">{{ $log->created_at->format('d/m/Y H:i:s') }}</p>
                        </li>
                    @endforeach
                </ol>
            </section>
        </div>
    </div>
</div>
