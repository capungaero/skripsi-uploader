<?php

namespace App\Jobs;

use App\Models\Submission;
use App\Services\ActivityLogger;
use App\Services\Cloud\CloudStorageManager;
use App\Services\Dspace\DspaceClient;
use App\Services\Dspace\DspaceException;
use App\Services\Dspace\MetadataMapper;
use App\Services\Staging;
use App\Services\SubmissionNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Publish an approved thesis as an archived DSpace item with the PDF in the ORIGINAL bundle.
 * Idempotent: the item uuid is saved as soon as it exists, and an existing bitstream is not re-uploaded.
 */
class DepositToDspace implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 1200;

    public function __construct(public int $submissionId)
    {
        $this->onQueue('dspace');
    }

    public function backoff(): array
    {
        return [120, 600];
    }

    public function handle(DspaceClient $dspace, MetadataMapper $mapper, CloudStorageManager $manager,
        SubmissionNotifier $notifier): void
    {
        $submission = Submission::with(['faculty', 'studyProgram'])->find($this->submissionId);
        if (! $submission || ! in_array($submission->status, [Submission::APPROVED, Submission::DEPOSIT_FAILED], true)) {
            return;
        }

        if (! $dspace->isConfigured()) {
            throw new DspaceException('DSpace belum dikonfigurasi atau belum diaktifkan.');
        }
        $collection = $submission->faculty?->dspace_collection_uuid;
        if (! $collection) {
            $this->fail(new DspaceException('UUID koleksi DSpace untuk fakultas "'.($submission->faculty?->name ?? '-').'" belum diisi.'));

            return;
        }

        [$path, $isTemp] = $this->localCopy($submission, $manager);

        try {
            $dspace->login();

            if (! $submission->dspace_item_uuid) {
                $item = $dspace->createItem($collection, $submission->judul, $mapper->map($submission));
                $submission->update(['dspace_item_uuid' => $item['uuid'], 'dspace_handle' => $item['handle']]);
                ActivityLogger::system('dspace.item_created', $submission, $item);
            }

            $bundle = $dspace->findBundle($submission->dspace_item_uuid)
                ?? ['uuid' => $dspace->createBundle($submission->dspace_item_uuid), 'bitstreams' => 0];
            if ($bundle['bitstreams'] === 0) {
                $dspace->uploadBitstream($bundle['uuid'], $path, $submission->cloudFileName());
            }

            if (! $submission->dspace_handle) {
                $submission->dspace_handle = $dspace->getItem($submission->dspace_item_uuid)['handle'] ?? null;
            }
        } finally {
            if ($isTemp) {
                @unlink($path);
            }
        }

        $submission->update([
            'status' => Submission::DEPOSITED,
            'deposited_at' => now(),
            'dspace_handle' => $submission->dspace_handle,
            'last_error' => null,
        ]);
        ActivityLogger::system('dspace.deposited', $submission, ['handle' => $submission->dspace_handle]);
        Staging::delete($submission);

        $notifier->notify($submission, 'deposited');
    }

    public function failed(?\Throwable $e): void
    {
        $submission = Submission::find($this->submissionId);
        if (! $submission || $submission->status === Submission::DEPOSITED) {
            return;
        }

        $message = mb_substr($e?->getMessage() ?? 'Unknown error', 0, 1000);
        $submission->update(['status' => Submission::DEPOSIT_FAILED, 'last_error' => $message]);
        ActivityLogger::system('dspace.failed', $submission, ['error' => $message]);
    }

    /** @return array{0: string, 1: bool} path and whether it is a temp download */
    private function localCopy(Submission $submission, CloudStorageManager $manager): array
    {
        if (Staging::exists($submission)) {
            return [Staging::path($submission), false];
        }
        if (! $submission->cloud_file_id) {
            throw new DspaceException('File tidak ditemukan di staging maupun di cloud.');
        }

        $temp = Staging::tempFile();
        $manager->driver($submission->cloud_provider)->downloadTo($submission->cloud_file_id, $temp);

        return [$temp, true];
    }
}
