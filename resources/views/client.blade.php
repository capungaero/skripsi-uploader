<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $schema['appearance']['title'] }}</title>
    <meta name="description" content="{{ $schema['appearance']['description'] }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/client.js'])
</head>
<body class="min-h-screen bg-canvas font-sans text-slate-700 antialiased">
<div x-data="uploader(@js($schema))" class="mx-auto max-w-6xl p-2 sm:p-6 lg:py-10">
<div class="rounded-[2.5rem] bg-white/60 p-3 shadow-float ring-1 ring-white/70 backdrop-blur sm:p-4">
<div class="grid gap-4 lg:grid-cols-[380px_minmax(0,1fr)]">

    {{-- Hero / instructions --}}
    <aside class="card-violet relative overflow-hidden p-6 sm:p-8 lg:sticky lg:top-6 lg:self-start">
        @if ($schema['appearance']['banner_url'])
            <img src="{{ $schema['appearance']['banner_url'] }}" alt="" class="absolute inset-0 h-full w-full object-cover opacity-25 mix-blend-luminosity">
        @endif
        <svg class="absolute right-0 bottom-0 h-40 w-full text-white/10" viewBox="0 0 380 160" preserveAspectRatio="none" fill="currentColor" aria-hidden="true">
            <path d="M0 160 C60 60 120 130 190 80 S320 20 380 40 V160Z"/>
        </svg>
        <div class="relative">
            <span class="icon-badge-soft mb-5"><x-icon name="library" class="h-6 w-6"/></span>
            <p class="text-xs tracking-wide text-brand-100 uppercase">UPT Perpustakaan Universitas Andalas</p>
            <h1 class="mt-1 text-2xl leading-snug font-semibold sm:text-3xl">{{ $schema['appearance']['title'] }}</h1>
            <p class="mt-3 text-sm leading-relaxed text-brand-100">{{ $schema['appearance']['description'] }}</p>

            @if ($schema['open'])
                <div class="mt-6 grid grid-cols-3 gap-2 text-center">
                    <div class="rounded-2xl bg-white/10 px-2 py-3 ring-1 ring-white/15">
                        <p class="text-lg font-semibold">{{ $schema['max_mb'] }} MB</p><p class="text-[11px] text-brand-100">maks. PDF</p>
                    </div>
                    <div class="rounded-2xl bg-white/20 px-2 py-3 ring-1 ring-white/25">
                        <p class="text-lg font-semibold">{{ $schema['max_attempts'] }}×</p><p class="text-[11px] text-brand-100">percobaan</p>
                    </div>
                    <div class="rounded-2xl bg-white/10 px-2 py-3 ring-1 ring-white/15">
                        <p class="text-sm leading-7 font-semibold">{{ $schema['academic_year'] }}</p><p class="text-[11px] text-brand-100">tahun akademik</p>
                    </div>
                </div>

                <div class="mt-6 rounded-3xl bg-white/10 p-4 ring-1 ring-white/15">
                    <p class="mb-2 flex items-center gap-2 text-sm font-semibold"><x-icon name="form" class="h-4 w-4"/> Petunjuk</p>
                    <div class="text-sm leading-relaxed whitespace-pre-line text-brand-50">{{ $schema['appearance']['instructions'] }}</div>
                </div>
            @endif
        </div>
    </aside>

    <main class="min-w-0">
    @if (! $schema['open'])
        <div class="card flex h-full flex-col items-center justify-center gap-3 p-10 text-center">
            <span class="icon-badge"><x-icon name="clock" class="h-6 w-6"/></span>
            <p class="text-slate-600">Pengunggahan skripsi sedang ditutup. Silakan hubungi perpustakaan.</p>
        </div>
    @else

    {{-- Form --}}
    <form x-show="stage === 'form'" x-cloak @submit.prevent="submit" class="card p-5 sm:p-8" novalidate>
        <div class="mb-6 flex items-center gap-3">
            <span class="icon-badge"><x-icon name="upload" class="h-6 w-6"/></span>
            <div>
                <h2 class="text-lg font-semibold text-slate-800">Formulir unggah skripsi</h2>
                <p class="text-xs text-slate-400">Kolom bertanda <span class="text-accent-500">*</span> wajib diisi</p>
            </div>
        </div>

        <div class="grid gap-x-5 gap-y-4 sm:grid-cols-2">
            <template x-for="field in schema.fields" :key="field.key">
                <div :class="field.type === 'textarea' ? 'sm:col-span-2' : ''">
                    <label class="label" :for="fieldId(field.key)">
                        <span x-text="field.label"></span><span x-show="field.required" class="text-accent-500"> *</span>
                    </label>

                    <template x-if="field.type === 'textarea'">
                        <textarea :id="fieldId(field.key)" x-model="values[field.key]" @blur="validateField(field)"
                                  :rows="field.key === 'abstrak' ? 5 : 2" :maxlength="field.max_length" :placeholder="field.placeholder"
                                  class="field-input" :class="errors[field.key] && 'ring-2 ring-accent-300'"></textarea>
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
                               :inputmode="field.key === 'nim' ? 'numeric' : null"
                               class="field-input" :class="errors[field.key] && 'ring-2 ring-accent-300'">
                    </template>

                    <p x-show="field.help && !errors[field.key]" x-text="field.help" class="mt-1 text-xs text-slate-400"></p>
                    <p x-show="errors[field.key]" x-text="errors[field.key]" class="mt-1 text-xs text-accent-600"></p>
                    <template x-if="field.key === 'nim' && nimState">
                        <p class="mt-1.5 inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs"
                           :class="nimState.allowed ? 'bg-emerald-50 text-emerald-700' : 'bg-accent-100 text-accent-600'"
                           x-text="nimState.allowed ? `NIM dapat digunakan · sisa percobaan ${nimState.remaining}/${nimState.max_attempts}` : nimState.message"></p>
                    </template>
                </div>
            </template>
        </div>

        {{-- File --}}
        <div class="mt-5">
            <span class="label">File PDF skripsi <span class="text-accent-500">*</span></span>
            <label class="flex cursor-pointer items-center gap-4 rounded-3xl border-2 border-dashed p-5 transition"
                   :class="dragging ? 'border-brand-400 bg-brand-50' : (fileError ? 'border-accent-300 bg-accent-100/40' : (file ? 'border-brand-300 bg-brand-50/60' : 'border-slate-200 bg-surface hover:border-brand-300'))"
                   @dragover.prevent="dragging = true" @dragleave.prevent="dragging = false" @drop.prevent="onDrop($event)">
                <input type="file" accept="application/pdf,.pdf" class="sr-only" @change="pickFile($event.target.files[0])">
                <span class="icon-badge" :class="file ? '' : 'bg-brand-400'"><x-icon name="document" class="h-6 w-6"/></span>
                <div class="min-w-0 text-sm">
                    <template x-if="!file">
                        <div><p class="font-medium text-slate-700">Klik atau seret file PDF ke sini</p>
                            <p class="text-xs text-slate-400">Satu file PDF lengkap, maksimal <span x-text="schema.max_mb"></span> MB</p></div>
                    </template>
                    <template x-if="file">
                        <div><p class="truncate font-medium text-slate-800" x-text="file.name"></p>
                            <p class="text-xs text-slate-400"><span x-text="formatSize(file.size)"></span> · klik untuk mengganti</p></div>
                    </template>
                </div>
            </label>
            <p x-show="fileError" x-text="fileError" class="mt-1 text-xs text-accent-600"></p>
        </div>

        <div x-show="formError" x-text="formError" class="mt-5 rounded-2xl bg-accent-100 px-4 py-3 text-sm text-accent-600"></div>

        <button type="submit" class="btn-primary mt-6 w-full py-3.5 text-base"><x-icon name="upload" class="h-5 w-5"/> Unggah Skripsi</button>
    </form>

    {{-- Uploading --}}
    <section x-show="stage === 'uploading'" x-cloak class="card flex flex-col items-center p-10 text-center">
        <span class="icon-badge mb-4 h-16 w-16 animate-pulse"><x-icon name="upload" class="h-7 w-7"/></span>
        <p class="font-semibold text-slate-800">Mengunggah file… <span x-text="progress + '%'"></span></p>
        <div class="progress-track mt-4 h-2.5 max-w-md">
            <div class="h-full rounded-full bg-gradient-to-r from-brand-500 to-accent-400 transition-all" :style="`width: ${progress}%`"></div>
        </div>
        <p class="mt-3 text-xs text-slate-400">Jangan tutup halaman ini sampai unggahan selesai.</p>
    </section>

    {{-- Status --}}
    <section x-show="stage === 'status'" x-cloak class="space-y-4">
        <template x-if="!status"><div class="card p-10 text-center text-slate-400">Memuat status…</div></template>
        <template x-if="status">
            <div class="space-y-4">
                <div class="relative overflow-hidden p-6 sm:p-8"
                     :class="status.state === 'rejected' ? 'card-pink' : 'card-violet'">
                    <svg class="absolute right-0 bottom-0 h-28 w-56 text-white/10" viewBox="0 0 224 112" fill="currentColor" aria-hidden="true"><path d="M0 112 L50 70 L90 86 L140 40 L224 14 V112Z"/></svg>
                    <div class="relative flex items-start gap-4">
                        <span class="icon-badge-soft h-14 w-14" :class="status.state === 'checking' && 'animate-pulse'">
                            <template x-if="status.state === 'checking'"><x-icon name="clock" class="h-7 w-7"/></template>
                            <template x-if="status.state === 'rejected'"><x-icon name="x" class="h-7 w-7"/></template>
                            <template x-if="['accepted', 'verified'].includes(status.state)"><x-icon name="check" class="h-7 w-7"/></template>
                            <template x-if="status.state === 'deposited'"><x-icon name="archive" class="h-7 w-7"/></template>
                        </span>
                        <div class="min-w-0">
                            <p class="text-xs text-white/75">Status unggahan</p>
                            <p class="text-2xl font-semibold" x-text="status.status_label"></p>
                            <p class="mt-1 text-sm text-white/85"><span x-text="status.nim"></span> · <span x-text="status.nama"></span></p>
                            <p class="mt-1 text-sm text-white/70" x-text="status.judul"></p>
                        </div>
                    </div>
                    <p class="relative mt-5 rounded-2xl bg-white/15 px-4 py-3 text-sm ring-1 ring-white/20">
                        <span x-show="status.state === 'checking'">File sedang diperiksa otomatis (tanda tangan, pengesahan, daftar isi, struktur bab). Biasanya 1–3 menit; halaman ini diperbarui sendiri.</span>
                        <span x-show="status.state === 'accepted'">File lolos pengecekan otomatis dan masuk antrean verifikasi manual pustakawan. Anda akan menerima notifikasi WhatsApp.</span>
                        <span x-show="status.state === 'verified'">Skripsi sudah diverifikasi pustakawan dan sedang disimpan ke repositori.</span>
                        <span x-show="status.state === 'deposited'">Skripsi tersimpan di repositori Universitas Andalas.
                            <a x-show="status.handle_url" :href="status.handle_url" target="_blank" rel="noopener" class="font-semibold underline">Lihat di repositori</a></span>
                        <span x-show="status.state === 'rejected'" x-text="status.can_reupload ? `Perbaiki file sesuai alasan di bawah lalu unggah ulang. Sisa percobaan: ${status.remaining_attempts}.` : 'Batas percobaan habis. Silakan hubungi perpustakaan.'"></span>
                    </p>
                </div>

                <template x-if="status.state === 'rejected' && status.reasons.length">
                    <div class="card p-5 sm:p-6">
                        <h3 class="mb-3 font-semibold text-slate-800">Alasan penolakan</h3>
                        <ul class="space-y-2">
                            <template x-for="r in status.reasons">
                                <li class="flex gap-3 rounded-2xl bg-accent-100/60 p-3 text-sm text-slate-700">
                                    <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-accent-500 text-white"><x-icon name="x" class="h-3 w-3"/></span>
                                    <span x-text="r"></span>
                                </li>
                            </template>
                        </ul>
                    </div>
                </template>

                <p x-show="status.admin_note && status.state !== 'rejected'" class="card p-5 text-sm text-slate-600">
                    <span class="font-semibold text-slate-800">Catatan pustakawan:</span> <span x-text="status.admin_note"></span>
                </p>

                <template x-if="status.criteria.length">
                    <div>
                        <h3 class="mb-3 px-1 font-semibold text-slate-800">Hasil pengecekan per kriteria</h3>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <template x-for="c in status.criteria">
                                <div class="card p-5">
                                    <div class="flex items-start gap-3">
                                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl text-white"
                                              :class="c.passed ? 'bg-brand-600' : 'bg-accent-500'">
                                            <template x-if="c.passed"><x-icon name="check" class="h-5 w-5"/></template>
                                            <template x-if="!c.passed"><x-icon name="x" class="h-5 w-5"/></template>
                                        </span>
                                        <div class="min-w-0 flex-1">
                                            <p class="font-medium text-slate-800" x-text="c.label"></p>
                                            <p class="mt-0.5 text-xs text-slate-400" x-text="c.reason"></p>
                                        </div>
                                    </div>
                                    <div class="mt-4 flex justify-between text-xs"><span class="text-slate-400">Skor</span><span class="font-semibold text-slate-700" x-text="c.score + '/100'"></span></div>
                                    <div class="progress-track mt-1.5"><div class="h-full rounded-full" :class="c.passed ? 'bg-emerald-500' : 'bg-accent-500'" :style="`width: ${c.score}%`"></div></div>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>

                <div class="card flex flex-wrap items-center gap-3 p-5">
                    <button x-show="status.can_reupload" @click="reupload" class="btn-primary"><x-icon name="upload" class="h-4 w-4"/> Unggah ulang</button>
                    <button @click="reset" class="btn-secondary">Unggah untuk NIM lain</button>
                    <p class="w-full text-xs text-slate-400 sm:ml-auto sm:w-auto">Simpan tautan halaman ini untuk memantau status. Tautan juga dikirim lewat WhatsApp.</p>
                </div>
            </div>
        </template>
    </section>
    @endif
    </main>
</div>
</div>
<footer class="mt-6 text-center text-xs text-brand-800/60">UPT Perpustakaan Universitas Andalas</footer>
</div>
</body>
</html>
