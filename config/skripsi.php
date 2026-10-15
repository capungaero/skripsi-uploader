<?php

return [
    // Directory holding pdftotext / pdftoppm (poppler-utils). Empty = rely on PATH.
    'poppler_path' => env('POPPLER_PATH', ''),

    // Local, non-public directory where uploads wait for the AI check and the cloud push.
    'staging_dir' => storage_path('app/staging'),

    // Where the "Server lokal" storage provider keeps approved files (private, not web-accessible).
    'local_dir' => env('LOCAL_STORAGE_DIR', storage_path('app/koleksi')),

    // Time zone of the WhatsApp sending window (Pengaturan > WhatsApp > Jam kirim).
    'timezone' => env('SKRIPSI_TIMEZONE', 'Asia/Jakarta'),

    // Staging files older than this are removed by the scheduler once they are no longer needed.
    'staging_ttl_hours' => (int) env('STAGING_TTL_HOURS', 24),
];
