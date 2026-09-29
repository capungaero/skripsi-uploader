<?php

namespace App\Services;

use App\Models\Submission;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/** Temporary, non-public home of an uploaded PDF until it is checked and pushed to the cloud. */
class Staging
{
    public static function dir(): string
    {
        $dir = config('skripsi.staging_dir');
        if (! is_dir($dir)) {
            mkdir($dir, 0770, true);
        }

        return $dir;
    }

    public static function store(UploadedFile $file): string
    {
        $name = now()->format('Ymd').'_'.Str::random(32).'.pdf';
        $file->move(self::dir(), $name);

        return $name;
    }

    public static function path(Submission $submission): ?string
    {
        if (! $submission->staging_path) {
            return null;
        }

        return self::dir().DIRECTORY_SEPARATOR.basename($submission->staging_path);
    }

    public static function exists(Submission $submission): bool
    {
        $path = self::path($submission);

        return $path !== null && is_file($path);
    }

    public static function delete(Submission $submission): void
    {
        $path = self::path($submission);
        if ($path && is_file($path)) {
            @unlink($path);
        }
        if ($submission->staging_path) {
            $submission->forceFill(['staging_path' => null])->save();
        }
    }

    /** A temp file path for downloads from the cloud; the caller deletes it. */
    public static function tempFile(): string
    {
        return self::dir().DIRECTORY_SEPARATOR.'tmp_'.Str::random(24).'.pdf';
    }
}
