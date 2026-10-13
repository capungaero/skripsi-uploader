<?php

namespace App\Jobs;

use App\Models\Submission;
use App\Services\ActivityLogger;
use App\Services\Cloud\CloudException;
use App\Services\Cloud\CloudStorage;
use App\Services\Cloud\CloudStorageManager;
use App\Services\Cloud\OAuthStorage;
use App\Services\Settings;
use App\Services\Staging;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Moves an AI-accepted PDF from staging to "Koleksi Karya Ilmiah/{Fakultas}/{NIM}_{Nama}.pdf". */
class PushToCloud implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 900;

    public function __construct(public int $submissionId)
    {
        $this->onQueue('cloud');
    }

    public function backoff(): array
    {
        return [60, 300, 900, 1800];
    }

    /** The file is already uploaded at this point, so a failing link lookup must not retry the upload. */
    public static function linkFor(CloudStorage $storage, string $fileId): ?string
    {
        try {
            return $storage->fileUrl($fileId);
        } catch (\Throwable) {
            return null;
        }
    }

    public function handle(CloudStorageManager $manager, Settings $settings): void
    {
        $submission = Submission::with('faculty')->find($this->submissionId);
        if (! $submission || $submission->cloud_file_id) {
            return;
        }
        if (in_array($submission->status, Submission::REJECTED, true)) {
            Staging::delete($submission);

            return;
        }

        $storage = $manager->active();
        if (! $storage || ! $storage->isConnected()) {
            // The file waits in staging; "Kirim ulang ke cloud" on the dashboard retries later.
            ActivityLogger::system('cloud.skipped', $submission, ['reason' => 'Cloud storage belum aktif/terhubung']);

            return;
        }

        $path = Staging::path($submission);
        if (! $path || ! is_file($path)) {
            throw new CloudException('Staging file is missing, cannot upload.');
        }

        $segments = [
            Submission::safeFolderName((string) $settings->get('cloud.root_folder')) ?: 'Koleksi Karya Ilmiah',
            Submission::safeFolderName($submission->faculty?->name ?? 'Tanpa Fakultas'),
        ];
        $fileName = $submission->cloudFileName();

        try {
            $folder = $storage->ensureFolder($segments);
            $fileId = $storage->upload($path, $folder, $fileName);
        } catch (CloudException $e) {
            if ($e->getCode() === 404) {
                OAuthStorage::forgetFolders($storage->name()); // folder removed remotely: rebuild cache
            }
            throw $e;
        }

        $submission->update([
            'cloud_provider' => $storage->name(),
            'cloud_file_id' => $fileId,
            'cloud_path' => implode('/', $segments).'/'.$fileName,
            'cloud_url' => self::linkFor($storage, $fileId),
        ]);
        ActivityLogger::system('cloud.uploaded', $submission, ['provider' => $storage->name(), 'path' => $submission->cloud_path]);

        // The admin may have approved while the upload was in flight.
        if (in_array($submission->status, [Submission::APPROVED, Submission::DEPOSITED, Submission::DEPOSIT_FAILED], true)) {
            FinalizeApproved::lockAndShare($submission, $storage, $settings);
        }
        Staging::delete($submission); // DSpace deposit downloads it back from the cloud
    }
}
