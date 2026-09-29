<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $schema['appearance']['title'] }}</title>
    <meta name="description" content="{{ $schema['appearance']['description'] }}">
    @vite(['resources/css/app.css', 'resources/js/client.js'])
</head>
<body class="min-h-screen bg-slate-50 font-sans text-slate-800 antialiased">
<div x-data="uploader(@js($schema))" class="mx-auto max-w-3xl px-4 py-8 sm:py-12">

    {{-- Header --}}
    <header class="mb-6">
        @if ($schema['appearance']['banner_url'])
            <img src="{{ $schema['appearance']['banner_url'] }}" alt="" class="mb-6 h-40 w-full rounded-xl object-cover sm:h-56">
        @endif
        <h1 class="text-2xl font-bold text-brand-800 sm:text-3xl">{{ $schema['appearance']['title'] }}</h1>
        <p class="mt-2 text-slate-600">{{ $schema['appearance']['description'] }}</p>
    </header>

    @if (! $schema['open'])
        <div class="card p-6 text-center text-slate-600">Pengunggahan skripsi sedang ditutup. Silakan hubungi perpustakaan.</div>
    @else

    {{-- Instructions --}}
    <section x-show="stage === 'form'" class="mb-6 rounded-xl border border-brand-100 bg-brand-50 p-4 text-sm leading-relaxed text-brand-800">
        <h2 class="mb-1 font-semibold">Petunjuk</h2>
        <div class="whitespace-pre-line">{{ $schema['appearance']['instructions'] }}</div>
        <p class="mt-2 text-xs text-brand-700">Tahun akademik {{ $schema['academic_year'] }} · PDF maks. {{ $schema['max_mb'] }} MB · maks. {{ $schema['max_attempts'] }} kali percobaan.</p>
    </section>

    {{-- Form --}}
    <form x-show="stage === 'form'" x-cloak @submit.prevent="submit" class="card space-y-5 p-5 sm:p-6" novalidate>
        <template x-for="field in schema.fields" :key="field.key">
            <div>
                <label class="label" :for="fieldId(field.key)">
                    <span x-text="field.label"></span><span x-show="field.required" class="text-red-600"> *</span>
                </label>

                <template x-if="field.type === 'textarea'">
                    <textarea :id="fieldId(field.key)" x-model="values[field.key]" @blur="validateField(field)"
                              :rows="field.key === 'abstrak' ? 6 : 3" :maxlength="field.max_length" :placeholder="field.placeholder"
                              class="field-input"></textarea>
                </template>

                <template x-if="field.type === 'faculty'">
                    <select :id="fieldId(field.key)" x-model="values[field.key]" @change="values.prodi = ''; validateField(field)" class="field-input">
                        <option value="">— Pilih fakultas —</option>
                        <template x-for="f in schema.faculties" :key="f.id"><option :value="f.id" x-text="f.name"></option></template>
                    </select>
                </template>

                <template x-if="field.type === 'program'">
                    <select :id="fieldId(field.key)" x-model="values[field.key]" @change="validateField(field)" :disabled="!values.fakultas" class="field-input">
                        <option value="" x-text="values.fakultas ? '— Pilih program studi —' : '— Pilih fakultas dulu —'"></option>
                        <template x-for="p in programs" :key="p.id"><option :value="p.id" x-text="p.name"></option></template>
                    </select>
                </template>

                <template x-if="field.type === 'select'">
                    <select :id="fieldId(field.key)" x-model="values[field.key]" @change="validateField(field)" class="field-input">
                        <option value="">— Pilih —</option>
                        <template x-for="o in field.options" :key="o"><option :value="o" x-text="o"></option></template>
                    </select>
                </template>

                <template x-if="['text', 'email', 'tel', 'number'].includes(field.type)">
                    <input :id="fieldId(field.key)" :type="field.type" x-model="values[field.key]"
                           @blur="field.key === 'nim' ? checkNim() : validateField(field)"
                           :maxlength="field.max_length" :placeholder="field.placeholder"
                           :inputmode="field.key === 'nim' ? 'numeric' : null" class="field-input">
                </template>

                <p x-show="field.help && !errors[field.key]" x-text="field.help" class="mt-1 text-xs text-slate-500"></p>
                <p x-show="errors[field.key]" x-text="errors[field.key]" class="mt-1 text-xs text-red-600"></p>
                <template x-if="field.key === 'nim' && nimState">
                    <p class="mt-1 text-xs" :class="nimState.allowed ? 'text-brand-700' : 'text-red-600'"
                       x-text="nimState.allowed ? `NIM dapat digunakan · sisa percobaan ${nimState.remaining} dari ${nimState.max_attempts}` : nimState.message"></p>
                </template>
            </div>
        </template>

        {{-- File --}}
        <div>
            <span class="label">File PDF skripsi <span class="text-red-600">*</span></span>
            <label class="flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed px-4 py-8 text-center transition"
                   :class="dragging ? 'border-brand-600 bg-brand-50' : (fileError ? 'border-red-300 bg-red-50' : 'border-slate-300 bg-slate-50 hover:border-brand-600')"
                   @dragover.prevent="dragging = true" @dragleave.prevent="dragging = false" @drop.prevent="onDrop($event)">
                <input type="file" accept="application/pdf,.pdf" class="sr-only" @change="pickFile($event.target.files[0])">
                <svg class="mb-2 h-8 w-8 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m0 0l-4 4m4-4l4 4M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2"/></svg>
                <template x-if="!file"><span class="text-sm text-slate-600">Klik atau seret file PDF ke sini (maks. <span x-text="schema.max_mb"></span> MB)</span></template>
                <template x-if="file"><span class="text-sm font-medium text-slate-800"><span x-text="file.name"></span> · <span x-text="formatSize(file.size)"></span></span></template>
            </label>
            <p x-show="fileError" x-text="fileError" class="mt-1 text-xs text-red-600"></p>
        </div>

        <div x-show="formError" x-text="formError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700"></div>

        <button type="submit" class="btn-primary w-full py-3">Unggah Skripsi</button>
    </form>

    {{-- Uploading --}}
    <section x-show="stage === 'uploading'" x-cloak class="card p-6 text-center">
        <p class="mb-3 font-medium">Mengunggah file… <span x-text="progress + '%'"></span></p>
        <div class="h-3 w-full overflow-hidden rounded-full bg-slate-200">
            <div class="h-full rounded-full bg-brand-600 transition-all" :style="`width: ${progress}%`"></div>
        </div>
        <p class="mt-3 text-xs text-slate-500">Jangan tutup halaman ini sampai unggahan selesai.</p>
    </section>

    {{-- Status --}}
    <section x-show="stage === 'status'" x-cloak class="card p-5 sm:p-6">
        <template x-if="!status"><p class="text-center text-slate-500">Memuat status…</p></template>
        <template x-if="status">
            <div>
                <div class="mb-4 flex items-start gap-3">
                    <span class="mt-1 inline-flex h-3 w-3 shrink-0 rounded-full"
                          :class="{ checking: 'animate-pulse bg-amber-400', accepted: 'bg-brand-600', verified: 'bg-brand-600', deposited: 'bg-brand-800', rejected: 'bg-red-600' }[status.state]"></span>
                    <div>
                        <p class="text-lg font-semibold" x-text="status.status_label"></p>
                        <p class="text-sm text-slate-600"><span x-text="status.nim"></span> · <span x-text="status.nama"></span></p>
                        <p class="mt-1 text-sm text-slate-500" x-text="status.judul"></p>
                    </div>
                </div>

                <p x-show="status.state === 'checking'" class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">
                    File sedang diperiksa otomatis (tanda tangan, pengesahan, daftar isi, struktur bab). Biasanya 1–3 menit; halaman ini diperbarui sendiri.
                </p>
                <p x-show="status.state === 'accepted'" class="rounded-lg bg-brand-50 px-3 py-2 text-sm text-brand-800">
                    File lolos pengecekan otomatis dan masuk antrean verifikasi manual pustakawan. Anda akan menerima notifikasi WhatsApp.
                </p>
                <p x-show="status.state === 'verified'" class="rounded-lg bg-brand-50 px-3 py-2 text-sm text-brand-800">
                    Skripsi sudah diverifikasi pustakawan dan sedang disimpan ke repositori.
                </p>
                <p x-show="status.state === 'deposited'" class="rounded-lg bg-brand-50 px-3 py-2 text-sm text-brand-800">
                    Skripsi tersimpan di repositori Universitas Andalas.
                    <a x-show="status.handle_url" :href="status.handle_url" target="_blank" rel="noopener" class="font-semibold underline">Lihat di repositori</a>
                </p>

                <template x-if="status.state === 'rejected'">
                    <div class="rounded-lg bg-red-50 p-3 text-sm text-red-800">
                        <p class="mb-1 font-semibold">Alasan penolakan:</p>
                        <ul class="list-disc space-y-1 pl-5"><template x-for="r in status.reasons"><li x-text="r"></li></template></ul>
                        <p class="mt-2" x-text="status.can_reupload ? `Perbaiki file lalu unggah ulang. Sisa percobaan: ${status.remaining_attempts}.` : 'Batas percobaan habis. Silakan hubungi perpustakaan.'"></p>
                    </div>
                </template>

                <p x-show="status.admin_note && status.state !== 'rejected'" class="mt-3 text-sm text-slate-600">
                    Catatan pustakawan: <span x-text="status.admin_note"></span>
                </p>

                <template x-if="status.criteria.length">
                    <div class="mt-5">
                        <h3 class="mb-2 text-sm font-semibold text-slate-700">Hasil pengecekan per kriteria</h3>
                        <ul class="divide-y divide-slate-100 rounded-lg border border-slate-200">
                            <template x-for="c in status.criteria">
                                <li class="flex items-start gap-3 px-3 py-2 text-sm">
                                    <span class="mt-0.5 font-bold" :class="c.passed ? 'text-brand-600' : 'text-red-600'" x-text="c.passed ? '✓' : '✗'"></span>
                                    <div class="flex-1"><p class="font-medium" x-text="c.label"></p><p class="text-xs text-slate-500" x-text="c.reason"></p></div>
                                    <span class="rounded bg-slate-100 px-2 py-0.5 text-xs font-semibold tabular-nums" x-text="c.score"></span>
                                </li>
                            </template>
                        </ul>
                    </div>
                </template>

                <div class="mt-6 flex flex-wrap gap-2">
                    <button x-show="status.can_reupload" @click="reupload" class="btn-primary">Unggah ulang</button>
                    <button @click="reset" class="btn-secondary">Unggah untuk NIM lain</button>
                </div>
                <p class="mt-4 text-xs text-slate-400">Simpan tautan halaman ini untuk memantau status. Tautan juga dikirim lewat WhatsApp.</p>
            </div>
        </template>
    </section>
    @endif

    <footer class="mt-10 text-center text-xs text-slate-400">UPT Perpustakaan Universitas Andalas</footer>
</div>
</body>
</html>
