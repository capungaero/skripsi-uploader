<?php

namespace App\Jobs;

use App\Models\Submission;
use App\Services\ActivityLogger;
use App\Services\Cloud\CloudStorage;
use App\Services\Cloud\CloudStorageManager;
use App\Services\Dspace\DspaceClient;
use App\Services\Settings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** After admin approval: lock the cloud file, optionally share it, and optionally deposit to DSpace. */
class FinalizeApproved implements ShouldQueue
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

    public function handle(CloudStorageManager $manager, Settings $settings, DspaceClient $dspace): void
    {
        $submission = Submission::find($this->submissionId);
        if (! $submission || $submission->status !== Submission::APPROVED) {
            return;
        }

        if ($submission->cloud_file_id && ! $submission->cloud_locked) {
            self::lockAndShare($submission, $manager->driver($submission->cloud_provider), $settings);
        }

        if ($settings->bool('dspace.auto_deposit') && $dspace->isConfigured()) {
            DepositToDspace::dispatch($submission->id);
        }
    }

    public static function lockAndShare(Submission $submission, CloudStorage $storage, Settings $settings): void
    {
        $locked = $storage->setReadOnly($submission->cloud_file_id);
        $share = $settings->bool('cloud.share_link') ? $storage->createShareLink($submission->cloud_file_id) : null;

        $submission->update([
            'cloud_locked' => $locked,
            'share_url' => $share ?? $submission->share_url,
        ]);
        ActivityLogger::system('cloud.finalized', $submission, ['read_only' => $locked, 'share_url' => $share]);
    }
}
