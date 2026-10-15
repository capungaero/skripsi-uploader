<?php

namespace Tests\Feature;

use App\Jobs\CheckSubmissionPdf;
use App\Jobs\PushToCloud;
use App\Jobs\RemoveFromCloud;
use App\Jobs\SendWhatsApp;
use App\Livewire\Admin\SubmissionShow;
use App\Models\Submission;
use App\Models\User;
use App\Services\Ai\AiClient;
use App\Services\Ai\SubmissionEvaluator;
use App\Services\Cloud\CloudStorageManager;
use App\Services\Pdf\PdfInspector;
use App\Services\RejectedFiles;
use App\Services\Settings;
use App\Services\SubmissionNotifier;
use App\Services\SubmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class RejectedFilesTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private string $keptDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->keptDir = storage_path('framework/testing/rejected_'.bin2hex(random_bytes(4)));
        config(['skripsi.rejected_dir' => $this->keptDir, 'skripsi.local_dir' => $this->keptDir.'_local']);
        $this->settings(['ai.base_url' => 'https://ai.test/v1', 'ai.api_key' => 'k', 'ai.model' => 'm', 'review.keep_rejected' => true]);
        Queue::fake([PushToCloud::class, SendWhatsApp::class]);
        Process::fake(['*pdftotext*' => Process::result(output: $this->pdfText()), '*pdftoppm*' => Process::result()]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->keptDir);
        File::deleteDirectory($this->keptDir.'_local');
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::create(['name' => 'A', 'email' => 'a@t.id', 'password' => 'password-123456', 'role' => 'superadmin', 'is_active' => true]);
    }

    private function aiRejects(): Submission
    {
        Http::fake(['ai.test/*' => Http::response($this->aiAnswer(['tanda_tangan_pembimbing' => 10]))]);
        $submission = $this->stagedSubmission();
        (new CheckSubmissionPdf($submission->id))->handle(app(PdfInspector::class), app(SubmissionEvaluator::class), app(AiClient::class), app(Settings::class), app(SubmissionNotifier::class));

        return $submission->refresh();
    }

    public function test_an_ai_rejected_pdf_is_kept_for_evaluation_and_staging_is_cleared(): void
    {
        $submission = $this->aiRejects();

        $this->assertSame(Submission::AI_REJECTED, $submission->status);
        $this->assertNull($submission->staging_path);
        $this->assertNotNull($submission->review_at);
        $this->assertMatchesRegularExpression('/^\d+_2011012345_Siti_Aminah\.pdf$/', $submission->review_path);
        $kept = app(RejectedFiles::class)->path($submission);
        $this->assertFileExists($kept);
        $this->assertStringStartsWith('%PDF', file_get_contents($kept));
        $this->assertDatabaseHas('activity_logs', ['action' => 'review.kept', 'submission_id' => $submission->id]);
    }

    public function test_keeping_can_be_switched_off(): void
    {
        $this->settings(['review.keep_rejected' => false]);

        $submission = $this->aiRejects();

        $this->assertNull($submission->review_path);
        $this->assertSame(0, app(RejectedFiles::class)->stats()['count']);
    }

    public function test_admin_can_open_the_kept_pdf_and_it_is_flagged_in_the_list(): void
    {
        $submission = $this->aiRejects();
        $this->actingAs($this->admin());

        $this->get(route('admin.submissions.file', $submission))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get('/admin/submissions?status=review')->assertOk()->assertSee('2011012345')->assertSee('PDF');
        Livewire::test(SubmissionShow::class, ['submission' => $submission])->assertSee('PDF disimpan untuk evaluasi sistem')->assertSee('Buka PDF');
    }

    public function test_review_filter_lists_only_uploads_with_a_kept_pdf(): void
    {
        $kept = $this->aiRejects();
        $this->stagedSubmission(['nim' => '2011099999', 'status' => Submission::AI_REJECTED, 'file_hash' => str_repeat('c', 64)]);
        $this->actingAs($this->admin());

        $this->get('/admin/submissions?status=review')->assertOk()->assertSee($kept->nim)->assertDontSee('2011099999');
    }

    public function test_deleting_one_file_removes_it_from_disk_and_the_record(): void
    {
        $submission = $this->aiRejects();
        $path = app(RejectedFiles::class)->path($submission);
        $this->actingAs($admin = $this->admin());

        Livewire::test(SubmissionShow::class, ['submission' => $submission])->call('deleteReviewFile')->assertSee('File PDF untuk evaluasi dihapus.');

        $this->assertFileDoesNotExist($path);
        $this->assertNull($submission->fresh()->review_path);
        $this->assertDatabaseHas('activity_logs', ['action' => 'review.deleted', 'submission_id' => $submission->id, 'actor_id' => $admin->id]);
        $this->get(route('admin.submissions.file', $submission))->assertNotFound();
    }

    public function test_delete_all_clears_every_kept_file(): void
    {
        $a = $this->aiRejects();
        Http::fake(['ai.test/*' => Http::response($this->aiAnswer(['tanda_tangan_pembimbing' => 10]))]);
        $second = $this->stagedSubmission(['nim' => '2011077777', 'file_hash' => str_repeat('d', 64)]);
        (new CheckSubmissionPdf($second->id))->handle(app(PdfInspector::class), app(SubmissionEvaluator::class), app(AiClient::class), app(Settings::class), app(SubmissionNotifier::class));

        $files = app(RejectedFiles::class);
        $this->assertSame(2, $files->stats()['count']);
        $this->assertSame(2, $files->deleteAll());
        $this->assertSame(0, $files->stats()['count']);
        $this->assertNull($a->fresh()->review_path);
    }

    public function test_retention_removes_only_files_older_than_the_limit(): void
    {
        $old = $this->aiRejects();
        $old->forceFill(['review_at' => now()->subDays(40)])->save();
        Http::fake(['ai.test/*' => Http::response($this->aiAnswer(['tanda_tangan_pembimbing' => 10]))]);
        $fresh = $this->stagedSubmission(['nim' => '2011066666', 'file_hash' => str_repeat('e', 64)]);
        (new CheckSubmissionPdf($fresh->id))->handle(app(PdfInspector::class), app(SubmissionEvaluator::class), app(AiClient::class), app(Settings::class), app(SubmissionNotifier::class));
        $files = app(RejectedFiles::class);

        $this->assertSame(0, $files->purgeExpired(), 'retention 0 never auto-deletes');

        $this->settings(['review.retention_days' => 30]);
        $this->assertSame(1, $files->purgeExpired());
        $this->assertNull($old->fresh()->review_path);
        $this->assertNotNull($fresh->fresh()->review_path);
    }

    public function test_an_admin_rejected_upload_in_storage_is_kept_before_the_cloud_copy_is_removed(): void
    {
        Queue::fake([PushToCloud::class, SendWhatsApp::class, RemoveFromCloud::class]);
        $this->settings(['cloud.provider' => 'local']);
        $local = app(CloudStorageManager::class)->driver('local');
        $src = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($src, '%PDF-1.4 admin rejected');
        $folder = $local->ensureFolder(['Koleksi', 'Fakultas Farmasi']);
        $id = $local->upload($src, $folder, '2011012345_Siti Aminah.pdf');
        @unlink($src);
        // already pushed to the storage, so nothing is left in staging
        $submission = $this->stagedSubmission(['status' => Submission::AI_ACCEPTED, 'cloud_provider' => 'local', 'cloud_file_id' => $id, 'staging_path' => null]);

        app(SubmissionService::class)->reject($submission, $this->admin(), 'Halaman pengesahan belum lengkap.');
        (new RemoveFromCloud($submission->id))->handle(app(CloudStorageManager::class));

        $submission->refresh();
        $this->assertNull($submission->cloud_file_id);
        $this->assertSame('%PDF-1.4 admin rejected', file_get_contents(app(RejectedFiles::class)->path($submission)));
    }

    public function test_the_admin_settings_page_shows_usage_and_saves_the_options(): void
    {
        $this->aiRejects();
        $this->actingAs($this->admin());

        Livewire::test(\App\Livewire\Admin\Settings\AiConfig::class)
            ->assertSee('File PDF yang ditolak')->assertSee('Tersimpan sekarang')
            ->set('form.review__keep_rejected', false)->set('form.review__retention_days', 14)->call('save')->assertHasNoErrors()
            ->set('form.review__retention_days', -1)->call('save')->assertHasErrors('form.review__retention_days')
            ->call('deleteReviewFiles')->assertSee('1 file PDF untuk evaluasi dihapus.');

        $this->assertFalse($this->settings()->bool('review.keep_rejected'));
        $this->assertSame(0, app(RejectedFiles::class)->stats()['count']);
    }
}
