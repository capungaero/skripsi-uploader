<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Submission;
use App\Services\ActivityLogger;
use App\Services\Cloud\CloudStorageManager;
use App\Services\Staging;

/** Admin-only PDF preview, streamed from staging or proxied from the cloud (the cloud file stays private). */
class SubmissionFileController extends Controller
{
    public function __invoke(Submission $submission, CloudStorageManager $manager)
    {
        $headers = [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$submission->cloudFileName().'"',
            'X-Content-Type-Options' => 'nosniff',
        ];

        ActivityLogger::admin('submission.file_viewed', $submission);

        if (Staging::exists($submission)) {
            return response()->file(Staging::path($submission), $headers);
        }

        abort_unless($submission->cloud_file_id, 404, 'File tidak tersedia (ditolak atau sudah dihapus).');

        $temp = Staging::tempFile();
        $manager->driver($submission->cloud_provider)->downloadTo($submission->cloud_file_id, $temp);

        return response()->file($temp, $headers)->deleteFileAfterSend();
    }
}
