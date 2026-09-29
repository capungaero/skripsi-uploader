@php
    // Smooth line chart geometry (SVG viewBox 600x160).
    $w = 600; $h = 160; $top = 22; $bottom = 138;
    $max = max(1, max(array_column($monthly, 'total')));
    $pts = [];
    foreach ($monthly as $i => $m) {
        $pts[] = [round($i * $w / 11, 1), round($bottom - ($m['total'] / $max) * ($bottom - $top), 1)];
    }
    $line = 'M'.$pts[0][0].','.$pts[0][1];
    for ($i = 0; $i < count($pts) - 1; $i++) {
        $p0 = $pts[max(0, $i - 1)]; $p1 = $pts[$i]; $p2 = $pts[$i + 1]; $p3 = $pts[min(count($pts) - 1, $i + 2)];
        $c1 = [round($p1[0] + ($p2[0] - $p0[0]) / 6, 1), round($p1[1] + ($p2[1] - $p0[1]) / 6, 1)];
        $c2 = [round($p2[0] - ($p3[0] - $p1[0]) / 6, 1), round($p2[1] - ($p3[1] - $p1[1]) / 6, 1)];
        $line .= " C{$c1[0]},{$c1[1]} {$c2[0]},{$c2[1]} {$p2[0]},{$p2[1]}";
    }
    $area = $line." L{$w},{$h} L0,{$h} Z";
    $fmt = fn ($n) => number_format($n, 0, ',', '.');
    $chart = array_map(fn ($m, $p) => $m + ['x' => $p[0], 'y' => $p[1]], $monthly, $pts);
@endphp

<div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_320px]">
    <div class="min-w-0 space-y-5">
        <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_260px]">

            {{-- Overview --}}
            <section class="card-violet relative overflow-hidden p-5 sm:p-6" x-data="{ sel: 11, pts: @js($chart) }">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="text-lg font-semibold">Ringkasan Unggahan</h2>
                    <select wire:model.live="year" aria-label="Tahun akademik"
                            class="rounded-xl border border-white/30 bg-white/10 px-3 py-1.5 text-xs text-white focus:outline-none [&>option]:text-slate-800">
                        <option value="">Semua tahun</option>
                        @foreach ($years as $y)<option value="{{ $y }}">{{ $y }}</option>@endforeach
                    </select>
                </div>

                <div class="relative mt-4">
                    <svg viewBox="0 0 {{ $w }} {{ $h }}" class="h-40 w-full overflow-visible" preserveAspectRatio="none" aria-hidden="true">
                        <defs>
                            <linearGradient id="areaFill" x1="0" x2="0" y1="0" y2="1">
                                <stop offset="0" stop-color="#ffffff" stop-opacity=".22"/>
                                <stop offset="1" stop-color="#ffffff" stop-opacity="0"/>
                            </linearGradient>
                        </defs>
                        <rect :x="Math.min(Math.max(pts[sel].x - 16, 0), {{ $w - 32 }})" y="0" width="32" height="{{ $h }}" rx="12" fill="#ffffff" fill-opacity=".14"/>
                        <path d="{{ $area }}" fill="url(#areaFill)"/>
                        <path d="{{ $line }}" fill="none" stroke="#f68bb2" stroke-width="3" stroke-linecap="round" vector-effect="non-scaling-stroke"/>
                    </svg>
                    <span class="pointer-events-none absolute h-4 w-4 -translate-x-1/2 -translate-y-1/2 rounded-full border-[3px] border-white bg-accent-400 shadow"
                          :style="`left: ${pts[sel].x / {{ $w }} * 100}%; top: ${pts[sel].y / {{ $h }} * 100}%`"></span>
                    {{-- Tooltip flips to the left of the point in the right half so it never leaves the card. --}}
                    <div class="pointer-events-none absolute -translate-y-full rounded-xl bg-brand-900/70 px-3 py-1.5 text-center text-xs backdrop-blur"
                         :class="pts[sel].x > {{ $w / 2 }} ? '-translate-x-full' : ''"
                         :style="`left: calc(${pts[sel].x / {{ $w }} * 100}% ${pts[sel].x > {{ $w / 2 }} ? '- 14px' : '+ 14px'}); top: calc(${Math.max(pts[sel].y / {{ $h }} * 100, 30)}% - 6px)`">
                        <p class="text-sm font-semibold" x-text="pts[sel].total.toLocaleString('id-ID')"></p>
                        <p class="text-white/70">unggahan</p>
                    </div>
                </div>

                <div class="mt-3 grid grid-cols-12 gap-0.5 text-[11px] text-brand-100 sm:text-xs">
                    <template x-for="(p, i) in pts" :key="i">
                        <button type="button" @click="sel = i" x-text="p.label"
                                class="rounded-full px-0 py-1 text-center transition"
                                :class="sel === i ? 'bg-white font-semibold text-brand-700' : 'hover:bg-white/15'"></button>
                    </template>
                </div>

                <div class="mt-5 grid grid-cols-3 gap-2 text-center">
                    <div class="py-2">
                        <p class="text-[11px] text-brand-100">Bulan terpilih</p>
                        <p class="text-xl font-semibold sm:text-2xl" x-text="pts[sel].total.toLocaleString('id-ID')"></p>
                        <p class="text-[11px] text-brand-100" x-text="pts[sel].full"></p>
                    </div>
                    <div class="rounded-2xl bg-white/15 py-2 ring-1 ring-white/20">
                        <p class="text-[11px] text-brand-100">Total unggahan</p>
                        <p class="text-xl font-semibold sm:text-3xl">{{ $fmt($total) }}</p>
                        <p class="text-[11px] text-brand-100">{{ $year ?: 'Semua tahun' }}</p>
                    </div>
                    <div class="py-2">
                        <p class="text-[11px] text-brand-100">Di DSpace</p>
                        <p class="text-xl font-semibold sm:text-2xl">{{ $fmt($deposited) }}</p>
                        <p class="text-[11px] text-brand-100">{{ $fmt($approved) }} disetujui</p>
                    </div>
                </div>
            </section>

            {{-- Highlight cards --}}
            <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-1">
                <a href="{{ route('admin.submissions', ['status' => 'queue']) }}" wire:navigate class="card-violet flex items-center gap-4 p-5 transition hover:-translate-y-0.5">
                    <span class="icon-badge-soft"><x-icon name="inbox" class="h-6 w-6"/></span>
                    <div>
                        <p class="text-sm text-brand-100">Antrean verifikasi</p>
                        <p class="text-3xl font-semibold">{{ $fmt($queueCount) }}</p>
                        <p class="text-xs text-brand-100">{{ $fmt($checking) }} sedang dicek AI</p>
                    </div>
                </a>
                <a href="{{ route('admin.submissions', ['status' => 'rejected']) }}" wire:navigate class="card-pink relative flex flex-col justify-between gap-6 overflow-hidden p-5 transition hover:-translate-y-0.5">
                    <svg class="absolute right-0 bottom-0 h-24 w-40 text-white/15" viewBox="0 0 160 96" fill="currentColor" aria-hidden="true"><path d="M0 96 L40 60 L70 72 L110 30 L160 10 L160 96Z"/></svg>
                    <div class="flex items-center gap-4">
                        <span class="icon-badge-soft"><x-icon name="x" class="h-6 w-6"/></span>
                        <p class="font-semibold">Ditolak</p>
                    </div>
                    <div class="relative flex items-end justify-between">
                        <div>
                            <p class="text-xs text-white/80">Total ditolak AI & admin</p>
                            <p class="text-3xl font-semibold">{{ $fmt($rejected) }}</p>
                            @if ($errorCount) <p class="text-xs text-white/90">{{ $fmt($errorCount) }} error perlu tindakan</p> @endif
                        </div>
                        <span class="flex h-9 w-9 items-center justify-center rounded-full ring-2 ring-white/70"><x-icon name="arrow-right" class="h-4 w-4"/></span>
                    </div>
                </a>
            </div>
        </div>

        {{-- Faculty cards --}}
        <section x-data="{ all: false }">
            <div class="mb-3 flex items-center justify-between px-1">
                <h2 class="font-semibold text-slate-800">Progres per fakultas</h2>
                <button type="button" @click="all = !all" class="text-xs font-medium text-brand-600 hover:underline" x-text="all ? 'Tampilkan ringkas' : 'Lihat semua'"></button>
            </div>
            <div class="grid gap-x-5 gap-y-10 pt-6 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($faculties as $i => $f)
                    @php
                        $row = $perFaculty[$f->id] ?? null;
                        $t = (int) ($row->total ?? 0);
                        $a = (int) ($row->approved ?? 0);
                        $pct = $t ? round($a / $t * 100) : 0;
                    @endphp
                    <a href="{{ route('admin.submissions', ['faculty' => $f->id, 'year' => $year ?: null]) }}" wire:navigate
                       @if ($i >= 6) x-show="all" x-cloak @endif
                       class="card relative block min-w-0 px-5 pt-10 pb-5 text-center transition hover:-translate-y-0.5">
                        <span class="icon-badge absolute -top-6 left-1/2 h-14 w-14 -translate-x-1/2"><x-icon name="building" class="h-6 w-6"/></span>
                        <p class="truncate font-semibold text-slate-800" title="{{ $f->name }}">{{ \Illuminate\Support\Str::after($f->name, 'Fakultas ') }}</p>
                        <p class="text-xs text-slate-400">{{ $fmt($t) }} unggahan</p>
                        <div class="mt-4 flex justify-between text-xs"><span class="text-slate-500">Disetujui</span><span class="font-semibold text-slate-700">{{ $pct }}%</span></div>
                        <div class="progress-track mt-1.5"><div class="progress-bar" style="width: {{ $pct }}%"></div></div>
                        <div class="mt-4 flex items-center justify-between text-xs">
                            <span class="text-slate-400">{{ $fmt($a) }} / {{ $fmt($t) }}</span>
                            @if (($row->queue ?? 0) > 0)
                                <span class="pill bg-accent-100 text-accent-600">{{ $fmt($row->queue) }} antre</span>
                            @else
                                <span class="pill bg-brand-50 text-brand-600">{{ $fmt($row->rejected ?? 0) }} ditolak</span>
                            @endif
                        </div>
                    </a>
                @endforeach
            </div>
        </section>
    </div>

    {{-- Right panel --}}
    <aside class="space-y-5">
        <section class="card p-5" x-data="{ tab: 'queue' }">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="flex items-center gap-2 font-semibold text-slate-800"><x-icon name="clock" class="h-5 w-5 text-slate-400"/> Terbaru</h2>
                <a href="{{ route('admin.submissions', ['status' => 'queue']) }}" wire:navigate class="text-xs text-slate-400 hover:text-brand-600">Lihat semua</a>
            </div>
            <div class="mb-4 grid grid-cols-2 rounded-2xl bg-surface p-1 text-xs font-medium">
                <button type="button" @click="tab = 'queue'" class="rounded-xl py-2 transition" :class="tab === 'queue' ? 'bg-brand-600 text-white shadow-soft' : 'text-slate-400'">Antrean</button>
                <button type="button" @click="tab = 'log'" class="rounded-xl py-2 transition" :class="tab === 'log' ? 'bg-brand-600 text-white shadow-soft' : 'text-slate-400'">Aktivitas</button>
            </div>

            <ul x-show="tab === 'queue'" class="space-y-1">
                @forelse ($queue as $s)
                    <li>
                        <a href="{{ route('admin.submissions.show', $s) }}" wire:navigate class="flex items-center gap-3 rounded-2xl p-2 hover:bg-surface">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-brand-100 text-xs font-semibold text-brand-700">{{ mb_strtoupper(mb_substr($s->nama, 0, 1)) }}</span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium text-slate-700">{{ $s->nama }}</p>
                                <p class="truncate text-xs text-slate-400">{{ $s->nim }} · {{ \Illuminate\Support\Str::after($s->faculty?->name, 'Fakultas ') }}</p>
                                <p class="text-[11px] text-slate-300">{{ $s->created_at->diffForHumans() }}</p>
                            </div>
                            <span class="pill bg-brand-50 text-brand-600">{{ $s->ai_score ?? '–' }}</span>
                        </a>
                    </li>
                @empty
                    <li class="py-8 text-center text-sm text-slate-400">Tidak ada antrean 🎉</li>
                @endforelse
            </ul>

            <ul x-show="tab === 'log'" x-cloak class="space-y-1">
                @forelse ($activity as $log)
                    <li class="flex items-center gap-3 rounded-2xl p-2">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full {{ ['admin' => 'bg-brand-100 text-brand-700', 'student' => 'bg-accent-100 text-accent-600'][$log->actor_type] ?? 'bg-slate-100 text-slate-500' }}">
                            <x-icon :name="['admin' => 'users', 'student' => 'upload'][$log->actor_type] ?? 'cog'" class="h-4 w-4"/>
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-mono text-xs text-slate-700">{{ $log->action }}</p>
                            <p class="truncate text-xs text-slate-400">{{ $log->submission?->nim ?? $log->actor_label }}</p>
                            <p class="text-[11px] text-slate-300">{{ $log->created_at->diffForHumans() }}</p>
                        </div>
                    </li>
                @empty
                    <li class="py-8 text-center text-sm text-slate-400">Belum ada aktivitas.</li>
                @endforelse
            </ul>
        </section>

        <section class="card p-5">
            <h2 class="mb-3 flex items-center gap-2 font-semibold text-slate-800"><x-icon name="activity" class="h-5 w-5 text-slate-400"/> Status layanan</h2>
            <ul class="space-y-2">
                @foreach ($systems as [$label, $ok, $icon])
                    <li class="flex items-center gap-3 rounded-2xl bg-surface p-3">
                        <span class="flex h-9 w-9 items-center justify-center rounded-xl {{ $ok ? 'bg-brand-600 text-white' : 'bg-white text-slate-400' }}"><x-icon :name="$icon" class="h-4 w-4"/></span>
                        <span class="flex-1 text-sm text-slate-600">{{ trim($label) }}</span>
                        <span class="pill {{ $ok ? 'bg-emerald-50 text-emerald-600' : 'bg-accent-100 text-accent-600' }}">{{ $ok ? 'Aktif' : 'Belum diatur' }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    </aside>
</div>
