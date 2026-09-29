# Skripsi Uploader — UPT Perpustakaan Universitas Andalas

Aplikasi ini melayani unggah mandiri PDF skripsi (±8.000 per tahun) dan terdiri dari dua sisi:

- **Halaman mahasiswa** (`/`) — form dinamis, validasi di browser, dan status unggahan yang diperbarui otomatis.
- **Dashboard admin** (`/admin`) — statistik, verifikasi kedua, export, dan seluruh pengaturan.

Alur sebuah unggahan:

1. PDF masuk ke folder staging di server.
2. AI memeriksa dokumen dan memberi skor per kriteria.
3. Jika diterima AI, file dipindah ke cloud (Google Drive, OneDrive, atau Dropbox) dan salinan di server dihapus.
4. Admin melakukan approve atau tolak.
5. Setelah approve, item disetor ke **DSpace 7+** sebagai item terbit, lengkap dengan bitstream PDF.

Mahasiswa menerima notifikasi WhatsApp pada setiap tahap.

Stack: Laravel 12, Livewire 4, Alpine.js, Tailwind 4, PostgreSQL (produksi), queue database, poppler-utils.

## Status unggahan

| Status | Tampilan mahasiswa | Keterangan |
|---|---|---|
| `checking` | Sedang dicek | Menunggu atau sedang diproses AI |
| `ai_error` | Sedang dicek | Provider AI gagal; admin menekan **Cek ulang AI**. Tidak mengurangi jatah percobaan |
| `ai_rejected` | Ditolak + alasan | File dihapus dari server; mengurangi 1 jatah percobaan |
| `ai_accepted` | Diterima – antrean verifikasi | File sudah/akan di cloud |
| `approved` | Terverifikasi | File cloud dikunci read-only, link share opsional |
| `admin_rejected` | Ditolak verifikator | File cloud dipindah ke trash; mengurangi 1 jatah percobaan |
| `deposited` | Tersimpan di repositori | Handle DSpace tersimpan |
| `deposit_failed` | Terverifikasi | Bisa disetor ulang dari dashboard |

- Jatah percobaan per NIM default 3 dan bisa diatur di menu Tampilan. Admin dapat meresetnya.
- NIM yang sudah punya unggahan aktif tidak bisa mengunggah lagi.

## Instalasi di server DSpace (Ubuntu/Debian)

```bash
sudo apt install php8.3-fpm php8.3-pgsql php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-intl poppler-utils
# Database terpisah di PostgreSQL yang sama dengan DSpace
sudo -u postgres psql -c "CREATE ROLE skripsi LOGIN PASSWORD '***';"
sudo -u postgres psql -c "CREATE DATABASE skripsi_uploader OWNER skripsi;"

cd /var/www/skripsi-uploader
composer install --no-dev --optimize-autoloader
npm ci && npm run build
cp .env.example .env && php artisan key:generate
# isi DB_CONNECTION=pgsql, DB_DATABASE=skripsi_uploader, DB_USERNAME=skripsi, APP_URL, APP_ENV=production, APP_DEBUG=false
php artisan migrate --seed --force
php artisan storage:link
php artisan app:create-admin          # superadmin pertama
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

> Simpan cadangan `APP_KEY`. Token API, secret OAuth, header WhatsApp, dan password DSpace dienkripsi dengan kunci ini.

### PHP (`/etc/php/8.3/fpm/conf.d/99-skripsi.ini`)

```ini
upload_max_filesize = 55M
post_max_size = 60M
max_execution_time = 120
memory_limit = 256M
```

### Nginx (server block terpisah, atau `location` di vhost DSpace)

```nginx
server {
    server_name unggah-skripsi.unand.ac.id;
    root /var/www/skripsi-uploader/public;
    index index.php;
    client_max_body_size 60M;

    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
    location ~ /\.(?!well-known) { deny all; }
}
```

### Queue worker (systemd)

Buat `/etc/systemd/system/skripsi-worker@.service`:

```ini
[Unit]
Description=Skripsi Uploader queue %i
After=network.target postgresql.service

[Service]
User=www-data
WorkingDirectory=/var/www/skripsi-uploader
ExecStart=/usr/bin/php artisan queue:work database --queue=%i --sleep=3 --max-time=3600
Restart=always

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl enable --now skripsi-worker@ai skripsi-worker@cloud skripsi-worker@notify skripsi-worker@dspace
```

Untuk menambah worker `ai` atau `cloud`, salin unit dengan nama instance berbeda, misalnya `skripsi-worker-ai2`.

Scheduler dijalankan lewat cron `www-data`. Tugasnya membersihkan file staging yang sudah tidak dibutuhkan.

```
* * * * * cd /var/www/skripsi-uploader && php artisan schedule:run >> /dev/null 2>&1
```

Setelah setiap deploy, restart worker dengan `php artisan queue:restart`.

## Konfigurasi awal di dashboard (superadmin)

1. **Fakultas & Prodi**:
   - Periksa daftar hasil seed.
   - Isi **UUID koleksi DSpace** per fakultas.
2. **AI & Kriteria**:
   - Isi base URL OpenAI-compatible, token, dan model.
   - Model harus mendukung *vision* untuk pengecekan tanda tangan.
   - Tekan **Tes koneksi**.
   - Sesuaikan instruksi, skor minimum, dan status wajib tiap kriteria.
3. **Penyimpanan Cloud**:
   - Pilih provider, lalu isi client ID dan secret.
   - Daftarkan Redirect URI yang ditampilkan di konsol provider.
   - Tekan **Hubungkan akun**, lalu **Tes provider aktif**.
4. **WhatsApp**:
   - Isi URL gateway, header token (JSON), dan template body berisi `{phone}` dan `{message}`.
   - Tekan **Kirim tes**.
5. **DSpace**:
   - Isi URL `/server`, lalu email dan password akun admin DSpace.
   - Tekan **Tes login**.
   - Aktifkan "Setor otomatis" jika diinginkan.
6. **Field Form**:
   - Atur field wajib/opsional per tahun akademik.
   - Tahun berikutnya dibuat lewat **Salin ke tahun baru** lalu **Aktifkan**.
7. **Pengguna Admin**:
   - Buat akun *verifikator* untuk pustakawan. Verifikator hanya bisa approve dan tolak.

## Pengembangan lokal

```bash
composer install && npm install && npm run build
php artisan migrate --seed
php artisan db:seed --class=LocalDevSeeder   # admin@skripsi.test, password dari DEV_ADMIN_PASSWORD di .env
php artisan serve
php artisan queue:work --queue=ai,cloud,notify,dspace
php artisan test
```

Catatan lingkungan lokal:

- Pengembangan lokal memakai SQLite.
- Tanpa `pdftoppm` (poppler), deteksi halaman kosong hanya berbasis teks dan tidak ada gambar halaman yang dikirim ke AI.
- Isi `POPPLER_PATH` di `.env` jika binary poppler tidak ada di PATH.
