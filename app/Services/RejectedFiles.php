<?php

namespace App\Services;

use App\Models\Submission;

/**
 * Keeps the PDF of a rejected upload so admins can open it and judge whether the system read it correctly.
 * Files live in a private folder (never web-accessible) until an admin deletes them, one by one or all at once.
 */
class RejectedFiles
{
    // The queue worker (root in Docker) writes these files and the web server (www-data) reads them.
    private const DIR_MODE = 0755;

    private const FILE_MODE = 0644;

    public function __construct(private Settings $settings) {}

    public function enabled(): bool
    {
        return $this->settings->bool('review.keep_rejected');
    }

    /** Days after which kept files are removed automatically; 0 = only when an admin deletes them. */
    public function retentionDays(): int
    {
        return max(0, (int) $this->settings->get('review.retention_days'));
    }

    public static function dir(): string
    {
        $dir = rtrim((string) config('skripsi.rejected_dir'), '/\\');
        if (! is_dir($dir)) {
            @mkdir($dir, self::DIR_MODE, true);
        }

        return $dir;
    }

    /** Keep the file at $path for evaluation (moved when $move). Returns false when keeping is off or the file is missing. */
    public function keep(Submission $submission, string $path, bool $move = false): bool
    {
        if (! $this->enabled() || ! is_file($path)) {
            return false;
        }

        $name = $submission->id.'_'.Submission::safeName($submission->nim).'_'.Submission::safeName($submission->nama).'.pdf';
        $target = self::dir().DIRECTORY_SEPARATOR.$name;
        $ok = $move ? (@rename($path, $target) || (@copy($path, $target) && @unlink($path))) : @copy($path, $target);
        if (! $ok) {
            return false;
        }

        @chmod($target, self::FILE_MODE);
        $submission->forceFill(['review_path' => $name, 'review_at' => now()])->save();
        ActivityLogger::system('review.kept', $submission);

        return true;
    }

    /** Keep the staged upload (the AI rejected it before it ever reached the storage). */
    public function keepStaged(Submission $submission): bool
    {
        return Staging::exists($submission) && $this->keep($submission, Staging::path($submission), true);
    }

    /** Absolute path of the kept file, or null when there is none. */
    public function path(Submission $submission): ?string
    {
        if (! $submission->review_path) {
            return null;
        }
        $path = self::dir().DIRECTORY_SEPARATOR.basename($submission->review_path);

        return is_file($path) ? $path : null;
    }

    /** Remove the file and the record. Returns false (and changes nothing) when the file cannot be removed, e.g. folder permissions. */
    public function delete(Submission $submission, bool $byAdmin = true): bool
    {
        if (($path = $this->path($submission)) && ! @unlink($path)) {
            return false;
        }
        $submission->forceFill(['review_path' => null, 'review_at' => null])->save();
        $byAdmin ? ActivityLogger::admin('review.deleted', $submission) : ActivityLogger::system('review.deleted', $submission);

        return true;
    }

    /** Move the kept file back into staging (it re-enters the pipeline) and clear the record. Returns the staging name. */
    public function release(Submission $submission): ?string
    {
        if (! ($path = $this->path($submission))) {
            return null;
        }
        $name = Staging::adopt($path);
        $submission->forceFill(['review_path' => null, 'review_at' => null])->save();

        return $name;
    }

    /** Delete every kept file. Returns how many submissions were cleared. */
    public function deleteAll(): int
    {
        $count = 0;
        foreach (Submission::whereNotNull('review_path')->cursor() as $submission) {
            $count += $this->delete($submission) ? 1 : 0;
        }

        return $count;
    }

    /** Remove files older than the retention period (no-op when retention is 0). Returns how many were removed. */
    public function purgeExpired(): int
    {
        $days = $this->retentionDays();
        if ($days === 0) {
            return 0;
        }

        $count = 0;
        foreach (Submission::whereNotNull('review_path')->where('review_at', '<', now()->subDays($days))->cursor() as $submission) {
            $count += $this->delete($submission, byAdmin: false) ? 1 : 0;
        }

        return $count;
    }

    /** @return array{count: int, bytes: int} */
    public function stats(): array
    {
        $bytes = 0;
        $count = 0;
        foreach (Submission::whereNotNull('review_path')->pluck('review_path') as $name) {
            $path = self::dir().DIRECTORY_SEPARATOR.basename($name);
            if (is_file($path)) {
                $count++;
                $bytes += (int) filesize($path);
            }
        }

        return ['count' => $count, 'bytes' => $bytes];
    }
}
