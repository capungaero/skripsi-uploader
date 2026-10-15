<?php

namespace App\Jobs;

use App\Models\Submission;
use App\Services\ActivityLogger;
use App\Services\Cloud\CloudStorageManager;
use App\Services\RejectedFiles;
use App\Services\Staging;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Trash the cloud copy of an admin-rejected thesis so the student's re-upload starts clean. */
class RemoveFromCloud implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $submissionId)
    {
        $this->onQueue('cloud');
    }

    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(CloudStorageManager $manager): void
    {
        $submission = Submission::find($this->submissionId);
        if (! $submission || ! $submission->cloud_file_id || $submission->status !== Submission::ADMIN_REJECTED) {
            return;
        }

        $driver = $manager->driver($submission->cloud_provider);
        $kept = app(RejectedFiles::class);
        if ($kept->enabled() && ! $submission->review_path) {
            // Keep a copy for evaluation before the cloud copy goes to the trash. A failed download retries the job.
            $tmp = Staging::tempFile();
            try {
                $driver->downloadTo($submission->cloud_file_id, $tmp);
                $kept->keep($submission, $tmp, true);
            } finally {
                @unlink($tmp);
            }
        }

        $driver->delete($submission->cloud_file_id);

        ActivityLogger::system('cloud.removed', $submission, ['path' => $submission->cloud_path]);
        $submission->update(['cloud_file_id' => null, 'cloud_url' => null, 'share_url' => null, 'cloud_locked' => false]);
    }
}
