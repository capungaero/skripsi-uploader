<?php

namespace App\Jobs;

use App\Models\Submission;
use App\Services\ActivityLogger;
use App\Services\Kliring\KliringClient;
use App\Services\Kliring\KliringSessionExpired;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/** After admin approval: accept the student's clearance request on kliringlib ("bebas skripsi"). */
class ApproveInKliring implements ShouldQueue
{
    use Queueable;

    /** How long after verification to keep looking for a clearance request the student has not filed yet. */
    public const WAIT_DAYS = 30;

    public const VERIFIED = [Submission::APPROVED, Submission::DEPOSITED, Submission::DEPOSIT_FAILED];

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(public int $submissionId)
    {
        $this->onQueue('kliring');
    }

    public function backoff(): array
    {
        return [60, 300];
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping((string) $this->submissionId))->dontRelease()];
    }

    public function handle(KliringClient $kliring): void
    {
        $submission = Submission::find($this->submissionId);
        if (! $submission || $submission->kliring_status === 'done' || ! in_array($submission->status, self::VERIFIED, true)
            || ! $kliring->isConfigured()) {
            return;
        }

        try {
            $request = $kliring->findRequest($submission->nim);
            if (! $request) {
                $this->wait($submission, 'waiting_request', 'Belum ada permohonan kliring untuk NIM ini.');

                return;
            }
            if (! $request['free']) {
                $kliring->approve($request['param']);
            }
        } catch (KliringSessionExpired $e) {
            // Waits for an admin to reconnect; the hourly retry picks it up again.
            if ($kliring->hasSession()) {
                $kliring->disconnect();
                ActivityLogger::system('kliring.session_expired');
            }
            $this->wait($submission, 'waiting_login', $e->getMessage());

            return;
        }

        $submission->update(['kliring_status' => 'done', 'kliring_at' => now(), 'kliring_error' => null]);
        ActivityLogger::system('kliring.approved', $submission, ['already_free' => $request['free']]);
    }

    public function failed(?\Throwable $e): void
    {
        $submission = Submission::find($this->submissionId);
        if (! $submission || $submission->kliring_status === 'done') {
            return;
        }

        $message = mb_substr($e?->getMessage() ?? 'Unknown error', 0, 1000);
        $submission->update(['kliring_status' => 'failed', 'kliring_error' => $message]);
        ActivityLogger::system('kliring.failed', $submission, ['error' => $message]);
    }

    /** Re-queue submissions that were waiting for a session or for the student's request. Returns how many. */
    public static function dispatchWaiting(): int
    {
        $waiting = Submission::whereIn('kliring_status', ['waiting_login', 'waiting_request'])
            ->whereIn('status', self::VERIFIED)->get();

        $count = 0;
        foreach ($waiting as $submission) {
            if ($submission->kliring_status === 'waiting_request' && $submission->verified_at?->lt(now()->subDays(self::WAIT_DAYS))) {
                $submission->update(['kliring_status' => 'failed',
                    'kliring_error' => 'Tidak ada permohonan kliring dalam '.self::WAIT_DAYS.' hari setelah verifikasi.']);
                ActivityLogger::system('kliring.failed', $submission, ['error' => 'no request']);

                continue;
            }
            self::dispatch($submission->id);
            $count++;
        }

        return $count;
    }

    /** Log only when the waiting reason changes, so hourly retries do not flood the activity log. */
    private function wait(Submission $submission, string $status, string $note): void
    {
        $changed = $submission->kliring_status !== $status;
        $submission->update(['kliring_status' => $status, 'kliring_error' => $note]);
        if ($changed) {
            ActivityLogger::system('kliring.'.$status, $submission);
        }
    }
}
