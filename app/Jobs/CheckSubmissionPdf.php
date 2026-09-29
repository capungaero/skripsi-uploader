<?php

namespace App\Jobs;

use App\Models\AiCriterion;
use App\Models\Submission;
use App\Models\SubmissionCheck;
use App\Services\ActivityLogger;
use App\Services\Ai\AiClient;
use App\Services\Ai\SubmissionEvaluator;
use App\Services\Pdf\InvalidPdfException;
use App\Services\Pdf\PdfInspector;
use App\Services\Settings;
use App\Services\Staging;
use App\Services\SubmissionNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Reads the staged PDF, asks the AI to score each active criterion, and accepts or rejects the upload. */
class CheckSubmissionPdf implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(public int $submissionId)
    {
        $this->onQueue('ai');
    }

    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(PdfInspector $inspector, SubmissionEvaluator $evaluator, AiClient $client,
        Settings $settings, SubmissionNotifier $notifier): void
    {
        $submission = Submission::find($this->submissionId);
        if (! $submission || $submission->status !== Submission::CHECKING) {
            return;
        }

        $path = Staging::path($submission);
        if (! $path || ! is_file($path)) {
            $this->fail(new \RuntimeException('Staging file is missing.'));

            return;
        }

        if ($this->attempts() === 1) {
            ActivityLogger::system('ai.check_started', $submission);
        }

        try {
            $report = $inspector->inspect($path);
        } catch (InvalidPdfException $e) {
            $this->reject($submission, [$e->getMessage()], $notifier);

            return;
        }
        $submission->update(['page_count' => $report->pageCount]);

        $criteria = AiCriterion::where('active', true)->orderBy('sort')->get();
        if (! $settings->bool('ai.enabled') || $criteria->isEmpty()) {
            $this->accept($submission, null, $notifier, 'AI nonaktif: langsung masuk antrean verifikasi manual.');

            return;
        }
        if (! $client->isConfigured()) {
            throw new \RuntimeException('AI belum dikonfigurasi (base URL, token, model).');
        }

        $started = microtime(true);
        $result = $evaluator->evaluate($path, $report, $criteria);

        SubmissionCheck::create([
            'submission_id' => $submission->id,
            'criteria' => $result->criteria,
            'overall_score' => $result->overallScore,
            'passed' => $result->passed,
            'model' => $client->model(),
            'raw_response' => $result->raw,
            'duration_ms' => (int) ((microtime(true) - $started) * 1000),
        ]);

        $submission->ai_score = $result->overallScore;
        $result->passed
            ? $this->accept($submission, $result->summary, $notifier)
            : $this->reject($submission, $result->rejectionReasons(), $notifier);
    }

    public function failed(?\Throwable $e): void
    {
        $submission = Submission::find($this->submissionId);
        if (! $submission || $submission->status !== Submission::CHECKING) {
            return;
        }

        $message = mb_substr($e?->getMessage() ?? 'Unknown error', 0, 1000);
        $submission->update(['status' => Submission::AI_ERROR, 'last_error' => $message]);
        SubmissionCheck::create(['submission_id' => $submission->id, 'error' => $message, 'passed' => false]);
        ActivityLogger::system('ai.error', $submission, ['error' => $message]);
    }

    private function accept(Submission $submission, ?string $summary, SubmissionNotifier $notifier, ?string $note = null): void
    {
        $submission->update([
            'status' => Submission::AI_ACCEPTED,
            'rejection_reasons' => null,
            'last_error' => $note,
        ]);
        ActivityLogger::system('ai.accepted', $submission, ['score' => $submission->ai_score, 'summary' => $summary]);

        PushToCloud::dispatch($submission->id);
        $notifier->notify($submission, 'ai_accepted');
    }

    private function reject(Submission $submission, array $reasons, SubmissionNotifier $notifier): void
    {
        $submission->update([
            'status' => Submission::AI_REJECTED,
            'rejection_reasons' => $reasons ?: ['Dokumen tidak memenuhi kriteria kelengkapan.'],
        ]);
        Staging::delete($submission);
        ActivityLogger::system('ai.rejected', $submission, ['score' => $submission->ai_score, 'reasons' => $reasons]);

        $notifier->notify($submission, 'ai_rejected');
    }
}
