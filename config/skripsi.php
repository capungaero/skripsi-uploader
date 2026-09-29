<?php

return [
    // Directory holding pdftotext / pdftoppm (poppler-utils). Empty = rely on PATH.
    'poppler_path' => env('POPPLER_PATH', ''),

    // Local, non-public directory where uploads wait for the AI check and the cloud push.
    'staging_dir' => storage_path('app/staging'),

    // Staging files older than this are removed by the scheduler once they are no longer needed.
    'staging_ttl_hours' => (int) env('STAGING_TTL_HOURS', 24),
];
