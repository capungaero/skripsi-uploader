<?php

namespace Tests\Feature;

use App\Jobs\FinalizeApproved;
use App\Jobs\PushToCloud;
use App\Jobs\RemoveFromCloud;
use App\Livewire\Admin\Settings\CloudStorage;
use App\Models\Submission;
use App\Models\User;
use App\Services\Cloud\CloudException;
use App\Services\Cloud\CloudStorageManager;
use App\Services\Cloud\LocalStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class LocalStorageTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private string $localDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->localDir = storage_path('framework/testing/local_'.bin2hex(random_bytes(4)));
        config(['skripsi.local_dir' => $this->localDir]);
        $this->settings(['cloud.provider' => 'local']);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->localDir)) {
            foreach (File::allFiles($this->localDir, true) as $file) {
                @chmod($file->getPathname(), 0660);
            }
            File::deleteDirectory($this->localDir);
        }
        @unlink(dirname($this->localDir).'/secret.txt');
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::create(['name' => 'Admin', 'email' => 'a@test.id', 'password' => 'password-123456', 'role' => 'superadmin', 'is_active' => true]);
    }

    private function pushed(): Submission
    {
        $submission = $this->stagedSubmission(['status' => Submission::AI_ACCEPTED]);
        (new PushToCloud($submission->id))->handle(app(CloudStorageManager::class), $this->settings());

        return $submission->refresh();
    }

    public function test_file_is_stored_on_the_server_and_staging_is_cleared(): void
    {
        Http::fake();

        $submission = $this->pushed();

        $this->assertSame('local', $submission->cloud_provider);
        $this->assertSame('Koleksi Karya Ilmiah/Fakultas Farmasi/2011012345_Siti Aminah.pdf', $submission->cloud_path);
        $this->assertSame($submission->cloud_path, $submission->cloud_file_id);
        $this->assertNull($submission->cloud_url);
        $this->assertNull($submission->staging_path);
        $this->assertFileExists($this->localDir.'/Koleksi Karya Ilmiah/Fakultas Farmasi/2011012345_Siti Aminah.pdf');
        Http::assertNothingSent();
    }

    public function test_approved_file_is_locked_without_any_share_link(): void
    {
        $this->settings(['cloud.share_link' => true]);
        $submission = $this->pushed();
        $submission->update(['status' => Submission::APPROVED]);

        FinalizeApproved::lockAndShare($submission, app(CloudStorageManager::class)->driver('local'), $this->settings());

        $submission->refresh();
        $this->assertTrue($submission->cloud_locked);
        $this->assertNull($submission->share_url);
        $this->assertFalse(is_writable($this->localDir.'/'.$submission->cloud_file_id));
    }

    public function test_reupload_replaces_a_locked_file(): void
    {
        $submission = $this->pushed();
        $storage = new LocalStorage;
        $storage->setReadOnly($submission->cloud_file_id);

        $source = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($source, 'NEW CONTENT');
        $folder = $storage->ensureFolder(['Koleksi Karya Ilmiah', 'Fakultas Farmasi']);
        $storage->upload($source, $folder, '2011012345_Siti Aminah.pdf');
        @unlink($source);

        $this->assertSame('NEW CONTENT', file_get_contents($this->localDir.'/'.$submission->cloud_file_id));
    }

    public function test_admin_opens_the_local_file_through_the_dashboard(): void
    {
        $submission = $this->pushed();
        file_put_contents($this->localDir.'/'.$submission->cloud_file_id, '%PDF-1.4 local copy');

        $this->actingAs($this->admin());
        $this->get(route('admin.submissions.file', $submission))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get(route('admin.submissions.show', $submission))->assertOk()
            ->assertSee('Server lokal')->assertSee(route('admin.submissions.file', $submission), false);
    }

    public function test_rejected_file_moves_to_trash(): void
    {
        $submission = $this->pushed();
        $submission->update(['status' => Submission::ADMIN_REJECTED]);

        (new RemoveFromCloud($submission->id))->handle(app(CloudStorageManager::class));

        $this->assertFileDoesNotExist($this->localDir.'/'.$submission->cloud_file_id);
        $this->assertCount(1, glob($this->localDir.'/_trash/*_2011012345_Siti Aminah.pdf'));
        $this->assertNull($submission->fresh()->cloud_file_id);
    }

    public function test_paths_cannot_escape_the_storage_root(): void
    {
        file_put_contents(dirname($this->localDir).'/secret.txt', 'x');
        $storage = new LocalStorage;

        $this->expectException(CloudException::class);
        $storage->downloadTo('../secret.txt', tempnam(sys_get_temp_dir(), 'x'));
    }

    public function test_settings_page_offers_and_saves_local_storage(): void
    {
        $this->settings(['cloud.provider' => 'none']);

        Livewire::actingAs($this->admin())->test(CloudStorage::class)
            ->assertSee('Server lokal')->assertSee($this->localDir)
            ->set('form.cloud__provider', 'local')->call('save')->assertHasNoErrors()
            ->call('testActive')->assertSee('Berhasil');

        $this->assertSame('local', $this->settings()->get('cloud.provider'));
        $this->get('/admin/oauth/local/connect')->assertNotFound();
    }
}
