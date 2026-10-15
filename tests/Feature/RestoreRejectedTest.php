<?php

namespace Tests\Feature;

use App\Jobs\CheckSubmissionPdf;
use App\Jobs\PushToCloud;
use App\Jobs\SendWhatsApp;
use App\Livewire\Admin\SubmissionShow;
use App\Models\Submission;
use App\Models\User;
use App\Services\Ai\AiClient;
use App\Services\Ai\SubmissionEvaluator;
use App\Services\Pdf\PdfInspector;
use App\Services\RejectedFiles;
use App\Services\Settings;
use App\Services\Staging;
use App\Services\SubmissionNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class RestoreRejectedTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private string $keptDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->keptDir = storage_path('framework/testing/restore_'.bin2hex(random_bytes(4)));
        config(['skripsi.rejected_dir' => $this->keptDir]);
        $this->settings(['ai.base_url' => 'https://ai.test/v1', 'ai.api_key' => 'k', 'ai.model' => 'm', 'review.keep_rejected' => true, 'wa.enabled' => true, 'wa.url' => 'https://wa.test/send']);
        Queue::fake([PushToCloud::class, SendWhatsApp::class]);
        Process::fake(['*pdftotext*' => Process::result(output: $this->pdfText()), '*pdftoppm*' => Process::result()]);
        $this->actingAs($this->admin());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->keptDir);
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::create(['name' => 'A', 'email' => 'a@t.id', 'password' => 'password-123456', 'role' => 'verifikator', 'is_active' => true]);
    }

    private function rejectedByAi(array $attrs = []): Submission
    {
        Http::fake(['ai.test/*' => Http::response($this->aiAnswer(['tanda_tangan_pembimbing' => 10]))]);
        $submission = $this->stagedSubmission($attrs);
        (new CheckSubmissionPdf($submission->id))->handle(app(PdfInspector::class), app(SubmissionEvaluator::class), app(AiClient::class), app(Settings::class), app(SubmissionNotifier::class));
        Queue::fake([PushToCloud::class, SendWhatsApp::class]); // forget the notifications of the rejection itself

        return $submission->refresh();
    }

    public function test_restoring_a_wrongly_rejected_upload_puts_it_back_in_the_queue_without_reuploading(): void
    {
        $submission = $this->rejectedByAi();
        $this->assertNotNull(app(RejectedFiles::class)->path($submission));

        Livewire::test(SubmissionShow::class, ['submission' => $submission])
            ->assertSee('Ternyata penolakan ini keliru?')->call('restore')->assertHasNoErrors()->assertSee('Dipulihkan ke antrean verifikasi');

        $submission->refresh();
        $this->assertSame(Submission::AI_ACCEPTED, $submission->status);
        $this->assertNull($submission->rejection_reasons);
        $this->assertNull($submission->review_path);
        $this->assertNull(app(RejectedFiles::class)->path($submission));
        $this->assertTrue(Staging::exists($submission), 'the PDF is back in staging');
        $this->assertStringStartsWith('%PDF', file_get_contents(Staging::path($submission)));
        Queue::assertPushed(PushToCloud::class, fn ($job) => $job->submissionId === $submission->id);
        Queue::assertPushed(SendWhatsApp::class, fn ($job) => $job->event === 'restored' && str_contains($job->message, 'tidak perlu diunggah ulang'));
        $this->assertDatabaseHas('activity_logs', ['action' => 'submission.restored', 'submission_id' => $submission->id]);
        $this->assertSame(3, app(\App\Services\SubmissionService::class)->remainingAttempts($submission->nim), 'the false rejection no longer counts as an attempt');
    }

    public function test_the_restored_upload_appears_in_the_verification_queue_and_can_be_approved(): void
    {
        $submission = $this->rejectedByAi();
        Livewire::test(SubmissionShow::class, ['submission' => $submission])->call('restore');

        $this->get('/admin/submissions?status=queue')->assertOk()->assertSee($submission->nim);
        Livewire::test(SubmissionShow::class, ['submission' => $submission->fresh()])->call('approve')->assertHasNoErrors();
        $this->assertSame(Submission::APPROVED, $submission->fresh()->status);
    }

    public function test_it_is_refused_when_the_student_already_has_another_active_upload(): void
    {
        $submission = $this->rejectedByAi();
        $this->stagedSubmission(['status' => Submission::AI_ACCEPTED, 'file_hash' => str_repeat('f', 64)]); // same NIM, active

        Livewire::test(SubmissionShow::class, ['submission' => $submission])->call('restore')->assertHasErrors('status');

        $this->assertSame(Submission::AI_REJECTED, $submission->fresh()->status);
        $this->assertNotNull(app(RejectedFiles::class)->path($submission->fresh()), 'the kept file stays');
    }

    public function test_it_is_refused_when_the_same_pdf_is_active_under_another_nim(): void
    {
        $submission = $this->rejectedByAi();
        $this->stagedSubmission(['nim' => '2011099999', 'status' => Submission::AI_ACCEPTED, 'file_hash' => $submission->file_hash]);

        Livewire::test(SubmissionShow::class, ['submission' => $submission])->call('restore')->assertHasErrors('status');
        $this->assertSame(Submission::AI_REJECTED, $submission->fresh()->status);
    }

    public function test_it_is_refused_without_a_file_and_the_button_is_hidden(): void
    {
        $submission = $this->stagedSubmission(['status' => Submission::AI_REJECTED, 'staging_path' => null]);

        Livewire::test(SubmissionShow::class, ['submission' => $submission])
            ->assertDontSee('Ternyata penolakan ini keliru?')->call('restore')->assertHasErrors('status');
    }

    public function test_only_rejected_uploads_can_be_restored(): void
    {
        $submission = $this->stagedSubmission(['status' => Submission::AI_ACCEPTED]);

        Livewire::test(SubmissionShow::class, ['submission' => $submission])->assertDontSee('Ternyata penolakan ini keliru?')->call('restore')->assertHasErrors('status');
        $this->assertSame(Submission::AI_ACCEPTED, $submission->fresh()->status);
    }

    public function test_an_admin_rejected_upload_whose_cloud_copy_still_exists_is_restored_in_place(): void
    {
        $submission = $this->stagedSubmission([
            'status' => Submission::ADMIN_REJECTED, 'staging_path' => null, 'cloud_provider' => 'local', 'cloud_file_id' => 'Koleksi/x.pdf',
            'admin_note' => 'Salah', 'rejection_reasons' => ['Salah'], 'verified_by' => auth()->id(), 'verified_at' => now(),
        ]);

        Livewire::test(SubmissionShow::class, ['submission' => $submission])->call('restore')->assertHasNoErrors();

        $submission->refresh();
        $this->assertSame(Submission::AI_ACCEPTED, $submission->status);
        $this->assertSame('Koleksi/x.pdf', $submission->cloud_file_id, 'the cloud copy is reused');
        $this->assertNull($submission->admin_note);
        $this->assertNull($submission->verified_at);
        Queue::assertNotPushed(PushToCloud::class);
    }

    public function test_the_restored_event_has_its_own_whatsapp_template_on_the_settings_page(): void
    {
        $this->assertStringContainsString('keliru', (string) $this->settings()->get('wa.tpl.restored'));
        $this->assertArrayHasKey('restored', SubmissionNotifier::EVENTS);
    }
}
