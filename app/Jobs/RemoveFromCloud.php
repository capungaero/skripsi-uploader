<?php

namespace App\Jobs;

use App\Models\Submission;
use App\Services\ActivityLogger;
use App\Services\Cloud\CloudStorageManager;
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

        $manager->driver($submission->cloud_provider)->delete($submission->cloud_file_id);

        ActivityLogger::system('cloud.removed', $submission, ['path' => $submission->cloud_path]);
        $submission->update(['cloud_file_id' => null, 'share_url' => null, 'cloud_locked' => false]);
    }
}
