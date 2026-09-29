<?php

namespace Tests\Feature;

use App\Jobs\CheckSubmissionPdf;
use App\Models\ActivityLog;
use App\Models\FormConfig;
use App\Models\StudyProgram;
use App\Models\Submission;
use App\Services\SubmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class UploadTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_client_page_renders_dynamic_form(): void
    {
        $this->get('/')->assertOk()->assertSee('Unggah Skripsi')->assertSee('Judul Skripsi', false);
    }

    public function test_valid_upload_is_staged_logged_and_queued_for_ai(): void
    {
        $response = $this->postJson('/api/submissions', $this->uploadPayload())->assertCreated();

        $submission = Submission::firstOrFail();
        $this->assertSame(Submission::CHECKING, $submission->status);
        $this->assertSame('checking', $response->json('state'));
        $this->assertSame($submission->public_token, $response->json('token'));
        $this->assertFileExists($this->stagingDir.'/'.$submission->staging_path);
        $this->assertSame('Dr. A; Dr. B', $submission->extra['pembimbing']);
        $this->assertSame('siti@student.unand.ac.id', $submission->extra['email']);
        Queue::assertPushedOn('ai', CheckSubmissionPdf::class);
        $this->assertDatabaseHas('activity_logs', ['action' => 'submission.uploaded', 'actor_type' => 'student']);

        $this->getJson('/api/status/'.$submission->public_token)->assertOk()
            ->assertJson(['state' => 'checking', 'nim' => '2011012345']);
    }

    public function test_rejects_non_pdf_content_and_oversize_files(): void
    {
        $fake = UploadedFile::fake()->createWithContent('skripsi.pdf', 'not a pdf at all');
        $this->postJson('/api/submissions', $this->uploadPayload(['file' => $fake]))->assertStatus(422);

        $this->settings(['upload.max_mb' => 1]);
        $this->postJson('/api/submissions', $this->uploadPayload(['file' => $this->fakePdf('big.pdf', 1100)]))
            ->assertStatus(422)->assertJsonValidationErrors('file');

        $this->assertSame(0, Submission::count());
    }

    public function test_required_fields_follow_admin_configuration(): void
    {
        $payload = $this->uploadPayload(['email' => '']);
        $this->postJson('/api/submissions', $payload)->assertStatus(422)->assertJsonValidationErrors('email');

        // Next year's form makes email optional.
        FormConfig::active()->fields()->where('key', 'email')->update(['required' => false]);
        $this->postJson('/api/submissions', $this->uploadPayload(['email' => '']))->assertCreated();
    }

    public function test_programme_must_belong_to_selected_faculty(): void
    {
        $otherProgram = StudyProgram::whereHas('faculty', fn ($q) => $q->where('code', 'FH'))->first();

        $this->postJson('/api/submissions', $this->uploadPayload(['prodi' => $otherProgram->id]))
            ->assertStatus(422)->assertJsonValidationErrors('prodi');
    }

    public function test_duplicate_nim_is_blocked_while_a_submission_is_active(): void
    {
        $this->stagedSubmission(['status' => Submission::AI_ACCEPTED]);

        $this->getJson('/api/nim/2011012345')->assertOk()->assertJson(['allowed' => false]);
        $this->postJson('/api/submissions', $this->uploadPayload())->assertStatus(422)->assertJsonValidationErrors('nim');
    }

    public function test_same_pdf_cannot_be_filed_under_another_nim(): void
    {
        $this->postJson('/api/submissions', $this->uploadPayload())->assertCreated();

        $this->postJson('/api/submissions', $this->uploadPayload(['nim' => '2011012999']))
            ->assertStatus(422)->assertJsonValidationErrors('file');
        $this->assertSame(1, Submission::count());
    }

    public function test_three_rejections_exhaust_attempts_until_admin_reset(): void
    {
        foreach (range(1, 3) as $i) {
            $this->stagedSubmission(['status' => Submission::AI_REJECTED, 'staging_path' => null]);
        }

        $this->getJson('/api/nim/2011012345')->assertJson(['allowed' => false, 'remaining' => 0]);
        $this->postJson('/api/submissions', $this->uploadPayload())->assertStatus(422);

        app(SubmissionService::class)->resetAttempts('2011012345');
        $this->getJson('/api/nim/2011012345')->assertJson(['allowed' => true, 'remaining' => 3]);
    }

    public function test_rejected_student_can_reupload_with_remaining_attempts(): void
    {
        $this->stagedSubmission(['status' => Submission::AI_REJECTED, 'staging_path' => null, 'rejection_reasons' => ['x']]);

        $this->getJson('/api/nim/2011012345')->assertJson(['allowed' => true, 'remaining' => 2]);
        $this->postJson('/api/submissions', $this->uploadPayload())->assertCreated();
        $this->assertSame(2, Submission::count());
        $this->assertGreaterThan(0, ActivityLog::count());
    }

    public function test_upload_closed_without_active_form(): void
    {
        FormConfig::query()->update(['is_active' => false]);

        $this->postJson('/api/submissions', $this->uploadPayload())->assertStatus(503);
    }
}
