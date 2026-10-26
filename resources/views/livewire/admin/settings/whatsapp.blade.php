<div class="max-w-4xl space-y-6" wire:poll.{{ $connecting ? '3s' : '30s' }}="refresh">

    @if ($flash)<div class="rounded-2xl bg-brand-50 px-4 py-3 text-sm text-brand-800">{{ $flash }}</div>@endif
    @if ($error)<div class="rounded-2xl bg-accent-100 px-4 py-3 text-sm text-red-700">{{ $error }}</div>@endif

    {{-- Link the number --}}
    <section class="card space-y-4 p-5">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-semibold">Nomor WhatsApp pengirim</h2>
            @if ($configured)
                <span class="pill {{ $status === 'ready' ? 'bg-emerald-50 text-emerald-600' : 'bg-accent-100 text-accent-600' }}">{{ $hasSession ? $statusLabel : 'Belum dihubungkan' }}</span>
            @endif
        </div>

        @if (! $configured)
            <p class="text-sm text-slate-600">Isi alamat server OpenWA dan API key sekali saja. Setelah itu nomor WhatsApp cukup dihubungkan dengan scan QR, seperti masuk ke WhatsApp Web.</p>
        @elseif ($status === 'ready')
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl bg-surface p-4">
                <div>
                    <p class="font-medium text-slate-800">{{ $session['pushName'] ?? 'WhatsApp' }}</p>
                    <p class="text-sm text-slate-500">+{{ $session['phone'] ?? '-' }}@if (! empty($session['connectedAt'])) · tersambung sejak {{ \Illuminate\Support\Carbon::parse($session['connectedAt'])->wib('d/m/Y H:i') }}@endif</p>
                </div>
                <button type="button" wire:click="disconnect" wire:confirm="Putuskan nomor ini? Notifikasi berhenti sampai nomor dihubungkan lagi." class="btn-secondary text-red-600">Putuskan nomor</button>
            </div>
        @elseif ($status === 'qr_ready' && $qr)
            <div class="flex flex-wrap items-center gap-6">
                <img src="{{ $qr }}" alt="QR WhatsApp" class="h-56 w-56 rounded-2xl border border-slate-200 bg-white p-2">
                <ol class="list-decimal space-y-1 pl-5 text-sm text-slate-600">
                    <li>Buka WhatsApp di HP yang nomornya dipakai sebagai pengirim.</li>
                    <li>Ketuk <strong>Perangkat tertaut</strong> → <strong>Tautkan perangkat</strong>.</li>
                    <li>Arahkan kamera ke QR ini. Kode diperbarui otomatis.</li>
                </ol>
            </div>
        @elseif ($connecting)
            <p class="text-sm text-slate-500" wire:loading.remove>{{ $statusLabel }} Mohon tunggu sebentar…</p>
        @else
            <p class="text-sm text-slate-600">Nomor belum tersambung. Tekan tombol di bawah, lalu scan QR yang muncul dengan WhatsApp di HP.</p>
            @if (! empty($session['lastError']))<p class="text-xs text-slate-500">Catatan: {{ $session['lastError'] }}</p>@endif
            <button type="button" wire:click="connect" wire:loading.attr="disabled" class="btn-primary">Hubungkan nomor WhatsApp</button>
        @endif

        <details class="text-sm" @if (! $configured) open @endif>
            <summary class="cursor-pointer text-xs text-slate-500">Sambungan OpenWA (alamat server dan API key)</summary>
            <form wire:submit="saveConnection" class="mt-3 grid gap-3 sm:grid-cols-2">
                <div>
                    <label class="label">Alamat OpenWA</label>
                    <input wire:model="form.wa__openwa_url" class="field-input font-mono text-sm" placeholder="http://openwa:2785">
                    @error('form.wa__openwa_url')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="label">API key</label>
                    <input type="password" wire:model="form.wa__openwa_key" autocomplete="new-password" class="field-input text-sm"
                           placeholder="{{ $this->hasSecret('wa.openwa_key') ? '•••••• tersimpan' : '' }}">
                </div>
                <div class="sm:col-span-2"><button class="btn-secondary">Simpan sambungan</button></div>
            </form>
        </details>
    </section>

    <form wire:submit="save" class="space-y-4">
        <div class="card space-y-3 p-5">
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="form.wa__enabled"> Kirim notifikasi WhatsApp otomatis ke mahasiswa</label>
            @if (! $hasSession || $status !== 'ready')
                <p class="text-xs text-slate-500">Notifikasi baru terkirim setelah nomor tersambung.</p>
            @endif
        </div>

        <div class="card space-y-4 p-5">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="font-semibold">Batas pengiriman (agar nomor tidak diblokir)</h2>
                <button type="button" wire:click="applySuggested" class="btn-secondary text-xs">Terapkan saran</button>
            </div>
            <p class="text-xs text-slate-500">WhatsApp bisa memblokir nomor yang mengirim terlalu cepat atau terlalu banyak. Pesan yang melewati batas <strong>tidak hilang</strong>: ditahan otomatis lalu dikirim begitu diizinkan. Isi <strong>0</strong> untuk tanpa batas.</p>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <label class="label" for="imin">Jeda minimal (detik)</label>
                    <input id="imin" type="number" min="0" wire:model="form.wa__interval_min" class="field-input">
                    @error('form.wa__interval_min')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="label" for="imax">Jeda maksimal (detik)</label>
                    <input id="imax" type="number" min="0" wire:model="form.wa__interval_max" class="field-input">
                    @error('form.wa__interval_max')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="label" for="mh">Maks. pesan per jam</label>
                    <input id="mh" type="number" min="0" wire:model="form.wa__max_per_hour" class="field-input">
                    @error('form.wa__max_per_hour')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="label" for="md">Maks. pesan per 24 jam</label>
                    <input id="md" type="number" min="0" wire:model="form.wa__max_per_day" class="field-input">
                    @error('form.wa__max_per_day')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
            </div>
            <p class="text-xs text-slate-500">Jeda antar dua pesan diacak antara nilai minimal dan maksimal, supaya pola pengirimannya tidak seperti robot.</p>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <label class="label" for="ws">Jam kirim: mulai (opsional)</label>
                    <input id="ws" type="time" wire:model="form.wa__window_start" class="field-input">
                    @error('form.wa__window_start')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="label" for="we">Jam kirim: selesai</label>
                    <input id="we" type="time" wire:model="form.wa__window_end" class="field-input">
                    @error('form.wa__window_end')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <p class="self-end pb-2 text-xs text-slate-500 sm:col-span-2">Di luar jam ini pesan ditahan sampai jam mulai berikutnya ({{ $timezone }}). Kosongkan keduanya untuk mengirim kapan saja.</p>
            </div>
            <div class="grid gap-2 rounded-2xl bg-surface p-3 text-sm sm:grid-cols-4">
                <p><span class="text-xs text-slate-500">Terkirim 1 jam terakhir</span><br><strong>{{ $usage['hour'] }}</strong>@if ((int) ($form['wa__max_per_hour'] ?? 0) > 0) <span class="text-slate-400">/ {{ (int) $form['wa__max_per_hour'] }}</span>@endif</p>
                <p><span class="text-xs text-slate-500">Terkirim 24 jam terakhir</span><br><strong>{{ $usage['day'] }}</strong>@if ((int) ($form['wa__max_per_day'] ?? 0) > 0) <span class="text-slate-400">/ {{ (int) $form['wa__max_per_day'] }}</span>@endif</p>
                <p><span class="text-xs text-slate-500">Menunggu giliran kirim</span><br><strong>{{ $usage['pending'] }}</strong> pesan</p>
                <p><span class="text-xs text-slate-500">Pesan berikutnya boleh dikirim</span><br><strong>{{ $usage['next_in'] > 0 ? 'dalam '.$usage['next_in'].' detik' : 'sekarang' }}</strong></p>
            </div>
        </div>

        <div class="card space-y-4 p-5">
            <h2 class="font-semibold">Login mahasiswa lewat WhatsApp</h2>
            <p class="text-xs leading-relaxed text-slate-500">Mahasiswa mengirim pesan <code>kliring_NIM</code> ke nomor perpustakaan lebih dulu, lalu sistem membalas dengan kode masuk. Karena mahasiswa yang memulai percakapan, nomor perpustakaan lebih aman dari pemblokiran. Balasan hanya dikirim untuk pesan berawalan <code>kliring</code>.</p>

            <div class="rounded-2xl bg-slate-50 p-3 text-sm">
                <p class="font-medium">Webhook pesan masuk</p>
                @if (! $hasSession)
                    <p class="mt-1 text-xs text-slate-500">Hubungkan nomor WhatsApp dulu.</p>
                @else
                    <p class="mt-1 text-xs">
                        @if ($webhook)
                            <span class="text-emerald-700">Terdaftar{{ ($webhook['active'] ?? true) ? '' : ' (nonaktif)' }}</span>
                            @if (! empty($webhook['lastTriggeredAt'])) · pesan terakhir diterima {{ \Illuminate\Support\Carbon::parse($webhook['lastTriggeredAt'])->wib() }} WIB @endif
                        @else
                            <span class="text-amber-700">Belum terdaftar: pesan dari mahasiswa belum sampai ke aplikasi.</span>
                        @endif
                    </p>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <button type="button" wire:click="registerWebhook" wire:loading.attr="disabled" class="btn-secondary text-xs">{{ $webhook ? 'Daftarkan ulang' : 'Daftarkan webhook' }}</button>
                        @if ($webhook)<button type="button" wire:click="testWebhook" wire:loading.attr="disabled" class="btn-secondary text-xs">Tes webhook</button>@endif
                    </div>
                    @if ($webhookResult)<p class="mt-2 text-xs text-slate-600">{{ $webhookResult }}</p>@endif
                @endif
            </div>

            <div class="grid gap-3 sm:grid-cols-3">
                <div class="sm:col-span-3">
                    <label class="label">Nomor perpustakaan yang ditampilkan ke mahasiswa</label>
                    <input wire:model="form.login__wa_number" class="field-input font-mono text-sm" placeholder="6281234567890">
                    @error('form.login__wa_number')<p class="mt-1 text-xs text-red-600">Nomor tidak valid (contoh: 6281234567890).</p>@enderror
                </div>
                <div>
                    <label class="label">Kode berlaku (menit)</label>
                    <input type="number" min="1" max="240" wire:model="form.login__code_minutes" class="field-input text-sm">
                </div>
                <div>
                    <label class="label">Sesi masuk berlaku (jam)</label>
                    <input type="number" min="1" max="72" wire:model="form.login__session_hours" class="field-input text-sm">
                </div>
            </div>

            <label class="flex items-start gap-2 rounded-2xl border border-amber-200 bg-amber-50 p-3 text-sm">
                <input type="checkbox" wire:model="form.login__enabled" class="mt-0.5">
                <span><strong>Wajibkan login sebelum mengunggah.</strong><br>
                    <span class="text-xs text-amber-800">Aktifkan setelah webhook terdaftar dan Anda mencoba sendiri: kirim <code>kliring_NIM</code> dari HP ke nomor perpustakaan, lalu pastikan kode diterima. Selama belum diaktifkan, halaman unggah tetap terbuka seperti biasa.</span></span>
            </label>

            <div>
                <label class="label">Pesan balasan berisi kode</label>
                <textarea wire:model="form.wa__tpl__login_code" rows="6" class="field-input text-sm"></textarea>
                <p class="mt-1 text-xs text-slate-500">Placeholder: <code>{nim} {kode} {menit} {link}</code></p>
            </div>
            <div>
                <label class="label">Pesan bila format salah (mis. <code>kliring</code> tanpa NIM)</label>
                <textarea wire:model="form.wa__tpl__login_help" rows="4" class="field-input text-sm"></textarea>
            </div>
        </div>

        <div class="card space-y-4 p-5">
            <h2 class="font-semibold">Template pesan</h2>
            <p class="text-xs text-slate-500">Placeholder: <code>{nama} {nim} {judul} {alasan} {catatan} {sisa_percobaan} {link} {handle}</code>. Kosongkan untuk tidak mengirim pesan pada kejadian tersebut.</p>
            @foreach ($events as $event => $label)
                <div>
                    <label class="label">{{ $label }}</label>
                    <textarea wire:model="form.wa__tpl__{{ $event }}" rows="4" class="field-input text-sm"></textarea>
                </div>
            @endforeach
        </div>

        <details class="card p-5" @if (($form['wa__driver'] ?? 'openwa') === 'custom') open @endif>
            <summary class="cursor-pointer font-semibold">Lanjutan: pakai gateway HTTP lain (Fonnte, Wablas, dll.)</summary>
            <div class="mt-4 space-y-4">
                <div>
                    <label class="label">Pengirim</label>
                    <select wire:model="form.wa__driver" class="field-input">
                        <option value="openwa">OpenWA (scan QR) — disarankan</option>
                        <option value="custom">Gateway HTTP kustom</option>
                    </select>
                </div>
                <div class="grid gap-3 sm:grid-cols-6">
                    <div class="sm:col-span-4">
                        <label class="label">URL endpoint</label>
                        <input wire:model="form.wa__url" class="field-input font-mono text-sm" placeholder="https://api.fonnte.com/send">
                        @error('form.wa__url')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div class="sm:col-span-1">
                        <label class="label">Method</label>
                        <select wire:model="form.wa__method" class="field-input"><option>POST</option><option>GET</option><option>PUT</option></select>
                    </div>
                    <div class="sm:col-span-1">
                        <label class="label">Body</label>
                        <select wire:model="form.wa__body_format" class="field-input"><option value="form">form</option><option value="json">json</option></select>
                    </div>
                </div>
                <div>
                    <label class="label">Header (JSON) — berisi token, disimpan terenkripsi</label>
                    <textarea wire:model="form.wa__headers" rows="2" class="field-input font-mono text-xs"
                              placeholder="{{ $this->hasSecret('wa.headers') ? '•••••• tersimpan (isi untuk mengganti)' : '{&quot;Authorization&quot;: &quot;TOKEN&quot;}' }}"></textarea>
                    @error('form.wa__headers')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="label">Template body (JSON) dengan placeholder <code>{phone}</code> dan <code>{message}</code></label>
                    <textarea wire:model="form.wa__body_template" rows="3" class="field-input font-mono text-xs"></textarea>
                    @error('form.wa__body_template')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
            </div>
        </details>
        <button class="btn-primary">Simpan</button>
    </form>

    <div class="card flex flex-wrap items-end gap-3 p-5">
        <div><label class="label">Kirim pesan tes ke nomor</label><input wire:model="testPhone" class="field-input" placeholder="08xxxxxxxxxx"></div>
        <button wire:click="sendTest" wire:loading.attr="disabled" class="btn-secondary">Kirim tes</button>
        @error('testPhone')<p class="w-full text-xs text-red-600">{{ $message }}</p>@enderror
        @if ($testResult)<p class="w-full rounded-2xl bg-surface px-4 py-3 font-mono text-xs">{{ $testResult }}</p>@endif
    </div>
</div>
