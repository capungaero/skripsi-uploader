<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * Admin-editable configuration stored in the `settings` table.
 * Values are JSON-encoded; secret keys are additionally encrypted with APP_KEY.
 */
class Settings
{
    private const CACHE_KEY = 'app_settings_v1';

    public const SECRETS = [
        'ai.api_key',
        'gdrive.client_secret', 'gdrive.refresh_token',
        'onedrive.client_secret', 'onedrive.refresh_token',
        'dropbox.app_secret', 'dropbox.refresh_token',
        'wa.headers', 'wa.openwa_key',
        'dspace.password',
        'kliring.session',
        'login.webhook_secret',
    ];

    public const DEFAULTS = [
        'appearance.title' => 'Unggah Karya Ilmiah — Perpustakaan Universitas Andalas',
        'appearance.description' => 'Layanan unggah mandiri file Karya Ilmiah untuk koleksi repositori Universitas Andalas.',
        'appearance.instructions' => "1. Siapkan file Karya Ilmiah lengkap dalam satu PDF (maks. 50 MB).\n2. Pastikan halaman pengesahan sudah ditandatangani pembimbing.\n3. Isi data dengan benar, lalu unggah. Status pengecekan tampil otomatis.",
        'appearance.banner_path' => null,

        'upload.max_mb' => 50,
        'upload.max_attempts' => 3,

        'ai.enabled' => true,
        'ai.base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai',
        'ai.api_key' => null,
        'ai.model' => 'gemini-2.5-flash',
        'ai.json_mode' => true,
        'ai.timeout' => 120,
        'ai.max_images' => 8,
        'ai.max_text_chars' => 24000,
        'ai.max_tokens' => 8000,

        'review.keep_rejected' => true, // keep the PDF of rejected uploads so admins can evaluate the system
        'review.retention_days' => 0, // 0 = keep until an admin deletes it

        'cloud.provider' => 'none', // none, gdrive, onedrive, dropbox, local
        'cloud.root_folder' => 'Koleksi Karya Ilmiah',
        'cloud.share_link' => false,
        'gdrive.client_id' => null,
        'gdrive.client_secret' => null,
        'gdrive.refresh_token' => null,
        'gdrive.parent_id' => null, // optional Shared Drive / folder id to place the root folder in
        'onedrive.client_id' => null,
        'onedrive.client_secret' => null,
        'onedrive.tenant' => 'common',
        'onedrive.refresh_token' => null,
        'dropbox.app_key' => null,
        'dropbox.app_secret' => null,
        'dropbox.refresh_token' => null,

        'wa.enabled' => false,
        'wa.driver' => 'openwa', // openwa (scan QR) or custom (generic HTTP gateway below)
        'wa.interval_min' => 10, // seconds between two messages (random between min and max)
        'wa.interval_max' => 25,
        'wa.max_per_hour' => 40, // 0 = no limit
        'wa.max_per_day' => 300, // per rolling 24 hours, 0 = no limit
        'wa.window_start' => null, // optional sending window, e.g. 07:00-21:00 (blank = any time)
        'wa.window_end' => null,
        'wa.openwa_url' => null,
        'wa.openwa_key' => null,
        'wa.openwa_session' => null,
        'wa.url' => null,
        'wa.method' => 'POST',
        'wa.headers' => '{"Authorization": "TOKEN"}',
        'wa.body_format' => 'form', // json or form
        'wa.body_template' => '{"target": "{phone}", "message": "{message}"}',
        'wa.tpl.ai_rejected' => "Halo {nama} ({nim}),\n\nFile Karya Ilmiah Anda *DITOLAK* oleh pengecekan otomatis karena:\n{alasan}\n\nSilakan perbaiki file lalu unggah ulang di {link}. Sisa percobaan: {sisa_percobaan}.\n\n— Perpustakaan Universitas Andalas",
        'wa.tpl.ai_accepted' => "Halo {nama} ({nim}),\n\nFile Karya Ilmiah Anda *DITERIMA* oleh pengecekan otomatis dan masuk antrean verifikasi manual oleh pustakawan. Pantau status di {link}.\n\n— Perpustakaan Universitas Andalas",
        'wa.tpl.admin_approved' => "Halo {nama} ({nim}),\n\nKarya Ilmiah Anda telah *DIVERIFIKASI* oleh pustakawan dan akan disimpan ke repositori.\n{catatan}\n\nUntuk surat bebas pustaka, silakan ajukan *permohonan kliring* di https://kliringlib.unand.ac.id dengan akun perpustakaan Anda. Permohonan akan disetujui otomatis.\n\n— Perpustakaan Universitas Andalas",
        'wa.tpl.admin_rejected' => "Halo {nama} ({nim}),\n\nKarya Ilmiah Anda *DITOLAK* pada verifikasi manual.\nCatatan pustakawan: {catatan}\n\nSilakan perbaiki lalu unggah ulang di {link}. Sisa percobaan: {sisa_percobaan}.\n\n— Perpustakaan Universitas Andalas",
        'login.enabled' => false, // students must log in with a WhatsApp code before uploading
        'login.wa_number' => null, // the library number shown on the page, e.g. 6281234567890
        'login.code_minutes' => 30,
        'login.session_hours' => 6,
        'login.webhook_secret' => null,
        'login.webhook_id' => null,
        'wa.tpl.login_code' => "Kode masuk unggah Karya Ilmiah untuk NIM *{nim}*:

*{kode}*

Masukkan kode ini di halaman unggah, atau ketuk tautan berikut:
{link}

Kode berlaku {menit} menit dan hanya untuk sekali masuk. Jangan bagikan kode ini ke siapa pun.

— Perpustakaan Universitas Andalas",
        'wa.tpl.login_help' => "Format pesan belum sesuai. Untuk masuk dan mengunggah Karya Ilmiah, kirim pesan:

kliring_NIM

Contoh: kliring_2011012345

— Perpustakaan Universitas Andalas",
        'wa.tpl.restored' => "Halo {nama} ({nim}),\n\nMohon maaf, penolakan sebelumnya pada Karya Ilmiah Anda keliru. Karya Ilmiah Anda sudah dikembalikan ke antrean verifikasi pustakawan dan *tidak perlu diunggah ulang*. Pantau status di {link}.\n\n— Perpustakaan Universitas Andalas",
        'wa.tpl.deposited' => "Halo {nama} ({nim}),\n\nKarya Ilmiah Anda sudah tersimpan di repositori Universitas Andalas: {handle}\n\n— Perpustakaan Universitas Andalas",

        'dspace.enabled' => false,
        'dspace.base_url' => null, // e.g. https://repository.unand.ac.id/server
        'dspace.email' => null,
        'dspace.password' => null,
        'dspace.auto_deposit' => false,
        'dspace.metadata_map' => [
            'judul' => 'dc.title',
            'nama' => 'dc.contributor.author',
            'nim' => 'dc.identifier.nim',
            'pembimbing' => 'dc.contributor.advisor',
            'abstrak' => 'dc.description.abstract',
            'prodi' => 'dc.contributor.department',
        ],
        'dspace.fixed_metadata' => [
            'dc.type' => 'Thesis',
            'dc.publisher' => 'Universitas Andalas',
            'dc.language.iso' => 'id',
        ],
    ];

    private ?array $values = null;

    public function get(string $key, mixed $default = null): mixed
    {
        $values = $this->all();

        if (array_key_exists($key, $values) && $values[$key] !== null) {
            return $values[$key];
        }

        return $default ?? (self::DEFAULTS[$key] ?? null);
    }

    public function bool(string $key): bool
    {
        return filter_var($this->get($key), FILTER_VALIDATE_BOOLEAN);
    }

    public function int(string $key): int
    {
        return (int) $this->get($key);
    }

    public function has(string $key): bool
    {
        return filled($this->all()[$key] ?? null);
    }

    public function set(string $key, mixed $value): void
    {
        $this->setMany([$key => $value]);
    }

    public function setMany(array $pairs): void
    {
        foreach ($pairs as $key => $value) {
            $secret = in_array($key, self::SECRETS, true);
            $encoded = $value === null ? null : json_encode($value, JSON_UNESCAPED_UNICODE);
            if ($secret && $encoded !== null) {
                $encoded = Crypt::encryptString($encoded);
            }
            Setting::updateOrCreate(['key' => $key], ['value' => $encoded, 'is_secret' => $secret]);
        }

        $this->flush();
    }

    public function flush(): void
    {
        $this->values = null;
        Cache::forget(self::CACHE_KEY);
    }

    /** @return array<string, mixed> decrypted values keyed by setting key */
    public function all(): array
    {
        if ($this->values !== null) {
            return $this->values;
        }

        $rows = Cache::rememberForever(self::CACHE_KEY, fn () => Setting::all(['key', 'value', 'is_secret'])
            ->map(fn ($s) => [$s->key, $s->value, $s->is_secret])->all());

        $values = [];
        foreach ($rows as [$key, $value, $secret]) {
            if ($value === null) {
                $values[$key] = null;

                continue;
            }
            try {
                $values[$key] = json_decode($secret ? Crypt::decryptString($value) : $value, true);
            } catch (\Throwable) {
                $values[$key] = null; // APP_KEY rotated: treat as unset instead of crashing
            }
        }

        return $this->values = $values;
    }
}
