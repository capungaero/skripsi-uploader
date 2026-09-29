<?php

namespace App\Services;

use App\Jobs\CheckSubmissionPdf;
use App\Jobs\DepositToDspace;
use App\Jobs\FinalizeApproved;
use App\Jobs\RemoveFromCloud;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/** Upload eligibility, attempt limits and the admin verification transitions. */
class SubmissionService
{
    public function __construct(private Settings $settings) {}

    public function maxAttempts(): int
    {
        return max(1, $this->settings->int('upload.max_attempts'));
    }

    public function usedAttempts(string $nim): int
    {
        return Submission::forNim($nim)->whereIn('status', Submission::REJECTED)
            ->where('counts_as_attempt', true)->count();
    }

    public function remainingAttempts(string $nim): int
    {
        return max(0, $this->maxAttempts() - $this->usedAttempts($nim));
    }

    /**
     * @return array{allowed: bool, message: ?string, status: ?string, remaining: int}
     */
    public function uploadState(string $nim): array
    {
        $remaining = $this->remainingAttempts($nim);
        $active = Submission::forNim($nim)->whereIn('status', Submission::ACTIVE)->latest('id')->first();

        if ($active) {
            return [
                'allowed' => false,
                'message' => 'NIM ini sudah memiliki unggahan dengan status "'.$active->statusLabel().'". Unggah ulang hanya bisa dilakukan jika unggahan ditolak.',
                'status' => $active->statusLabel(),
                'remaining' => $remaining,
            ];
        }

        if ($remaining === 0) {
            return [
                'allowed' => false,
                'message' => 'Batas '.$this->maxAttempts().' kali percobaan unggah untuk NIM ini sudah habis. Silakan hubungi Perpustakaan Universitas Andalas.',
                'status' => null,
                'remaining' => 0,
            ];
        }

        return ['allowed' => true, 'message' => null, 'status' => null, 'remaining' => $remaining];
    }

    public function approve(Submission $submission, User $admin, ?string $note): void
    {
        $this->assertStatus($submission, [Submission::AI_ACCEPTED]);

        $submission->update([
            'status' => Submission::APPROVED,
            'admin_note' => $note,
            'verified_by' => $admin->id,
            'verified_at' => now(),
            'last_error' => null,
        ]);
        ActivityLogger::admin('submission.approved', $submission, ['note' => $note]);

        FinalizeApproved::dispatch($submission->id);
        app(SubmissionNotifier::class)->notify($submission, 'admin_approved');
    }

    public function reject(Submission $submission, User $admin, string $note): void
    {
        $this->assertStatus($submission, [Submission::AI_ACCEPTED, Submission::APPROVED, Submission::DEPOSIT_FAILED]);

        $submission->update([
            'status' => Submission::ADMIN_REJECTED,
            'admin_note' => $note,
            'rejection_reasons' => [$note],
            'verified_by' => $admin->id,
            'verified_at' => now(),
        ]);
        ActivityLogger::admin('submission.rejected', $submission, ['note' => $note]);

        Staging::delete($submission);
        if ($submission->cloud_file_id) {
            RemoveFromCloud::dispatch($submission->id);
        }
        app(SubmissionNotifier::class)->notify($submission, 'admin_rejected');
    }

    public function deposit(Submission $submission): void
    {
        $this->assertStatus($submission, [Submission::APPROVED, Submission::DEPOSIT_FAILED]);
        ActivityLogger::admin('dspace.deposit_requested', $submission);
        DepositToDspace::dispatch($submission->id);
    }

    /** Re-run the AI check after a provider outage (status ai_error). */
    public function recheck(Submission $submission): void
    {
        $this->assertStatus($submission, [Submission::AI_ERROR]);
        if (! Staging::exists($submission)) {
            throw ValidationException::withMessages(['status' => 'File staging sudah tidak ada; minta mahasiswa mengunggah ulang.']);
        }

        $submission->update(['status' => Submission::CHECKING, 'last_error' => null]);
        ActivityLogger::admin('ai.recheck_requested', $submission);
        CheckSubmissionPdf::dispatch($submission->id);
    }

    /** Give a student a fresh set of attempts. */
    public function resetAttempts(string $nim): int
    {
        $count = Submission::forNim($nim)->whereIn('status', Submission::REJECTED)
            ->where('counts_as_attempt', true)->update(['counts_as_attempt' => false]);
        ActivityLogger::admin('attempts.reset', null, ['nim' => $nim, 'count' => $count]);

        return $count;
    }

    private function assertStatus(Submission $submission, array $allowed): void
    {
        if (! in_array($submission->status, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => 'Aksi tidak bisa dilakukan pada status "'.$submission->adminStatusLabel().'".',
            ]);
        }
    }
}
