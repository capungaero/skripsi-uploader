<?php

namespace Tests\Feature;

use App\Jobs\CheckSubmissionPdf;
use App\Jobs\PushToCloud;
use App\Jobs\SendWhatsApp;
use App\Models\AiCriterion;
use App\Models\Submission;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiRequestException;
use App\Services\Ai\SubmissionEvaluator;
use App\Services\Pdf\PdfInspector;
use App\Services\Settings;
use App\Services\SubmissionNotifier;
use App\Services\SubmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AiCheckTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([PushToCloud::class, SendWhatsApp::class]);
        $this->settings([
            'ai.base_url' => 'https://ai.test/v1',
            'ai.api_key' => 'secret-key',
            'ai.model' => 'vision-model',
            'wa.enabled' => true,
            'wa.url' => 'https://wa.test/send',
        ]);
        Process::fake([
            '*pdftotext*' => Process::result(output: $this->pdfText()),
            '*pdftoppm*' => Process::result(exitCode: 1), // no renderer: text-only evaluation
        ]);
    }

    private function runJob(Submission $submission): void
    {
        (new CheckSubmissionPdf($submission->id))->handle(
            app(PdfInspector::class),
            app(SubmissionEvaluator::class),
            app(AiClient::class),
            app(Settings::class),
            app(SubmissionNotifier::class),
        );
    }

    public function test_passing_document_is_accepted_and_moves_to_cloud_queue(): void
    {
        Http::fake(['ai.test/*' => Http::response($this->aiAnswer([]))]);
        $submission = $this->stagedSubmission();

        $this->runJob($submission);

        $submission->refresh();
        $this->assertSame(Submission::AI_ACCEPTED, $submission->status);
        $this->assertSame(7, $submission->page_count);
        $this->assertSame(90, $submission->ai_score);
        $this->assertCount(5, $submission->checks->first()->criteria);
        Queue::assertPushed(PushToCloud::class);
        Queue::assertPushed(SendWhatsApp::class, fn ($job) => $job->event === 'ai_accepted');

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return $request->url() === 'https://ai.test/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer secret-key')
                && $body['model'] === 'vision-model'
                && str_contains(json_encode($body['messages']), 'LEMBAR PENGESAHAN');
        });
    }

    public function test_failing_required_criterion_rejects_with_specific_reason(): void
    {
        Http::fake(['ai.test/*' => Http::response($this->aiAnswer(['tanda_tangan_pembimbing' => 20]))]);
        $submission = $this->stagedSubmission();

        $this->runJob($submission);

        $submission->refresh();
        $this->assertSame(Submission::AI_REJECTED, $submission->status);
        $this->assertSame(['Tanda tangan dosen pembimbing: Alasan tanda_tangan_pembimbing'], $submission->rejection_reasons);
        $this->assertNull($submission->staging_path);
        Queue::assertNotPushed(PushToCloud::class);
        Queue::assertPushed(SendWhatsApp::class, fn ($job) => $job->event === 'ai_rejected'
            && str_contains($job->message, 'Alasan tanda_tangan_pembimbing') && str_contains($job->message, 'Sisa percobaan: 2'));
    }

    public function test_optional_criterion_failure_does_not_reject(): void
    {
        AiCriterion::where('key', 'halaman_utuh')->update(['required' => false]);
        Http::fake(['ai.test/*' => Http::response($this->aiAnswer(['halaman_utuh' => 10]))]);
        $submission = $this->stagedSubmission();

        $this->runJob($submission);

        $this->assertSame(Submission::AI_ACCEPTED, $submission->fresh()->status);
    }

    public function test_corrupt_pdf_is_rejected_without_calling_ai(): void
    {
        Http::fake();
        Process::fake(['*pdftotext*' => Process::result(errorOutput: 'Syntax Error: Couldn\'t read xref table', exitCode: 1)]);
        $submission = $this->stagedSubmission();

        $this->runJob($submission);

        $submission->refresh();
        $this->assertSame(Submission::AI_REJECTED, $submission->status);
        $this->assertStringContainsString('rusak', $submission->rejection_reasons[0]);
        Http::assertNothingSent();
    }

    public function test_provider_failure_marks_ai_error_without_using_an_attempt(): void
    {
        Http::fake(['ai.test/*' => Http::response('overloaded', 503)]);
        $submission = $this->stagedSubmission();

        try {
            $this->runJob($submission);
            $this->fail('Expected exception');
        } catch (AiRequestException $e) {
            (new CheckSubmissionPdf($submission->id))->failed($e); // what the worker does after the last try
        }

        $submission->refresh();
        $this->assertSame(Submission::AI_ERROR, $submission->status);
        $this->assertNotNull($submission->staging_path);
        $this->assertSame(3, app(SubmissionService::class)->remainingAttempts($submission->nim));
    }

    public function test_parse_handles_fences_clamps_scores_and_requires_every_criterion(): void
    {
        $evaluator = app(SubmissionEvaluator::class);
        $criteria = AiCriterion::where('active', true)->orderBy('sort')->get();
        $items = $criteria->map(fn ($c) => ['key' => $c->key, 'score' => 150, 'reason' => 'ok'])->all();

        $result = $evaluator->parse("```json\n".json_encode(['criteria' => $items])."\n```", $criteria);
        $this->assertTrue($result->passed);
        $this->assertSame(100, $result->criteria[0]['score']);

        $this->expectException(AiRequestException::class);
        $evaluator->parse(json_encode(['criteria' => array_slice($items, 1)]), $criteria);
    }

    public function test_ai_disabled_sends_everything_to_manual_queue(): void
    {
        $this->settings(['ai.enabled' => false]);
        Http::fake();
        $submission = $this->stagedSubmission();

        $this->runJob($submission);

        $this->assertSame(Submission::AI_ACCEPTED, $submission->fresh()->status);
        Http::assertNothingSent();
    }
}
