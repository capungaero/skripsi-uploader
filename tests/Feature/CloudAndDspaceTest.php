<?php

namespace Tests\Feature;

use App\Jobs\DepositToDspace;
use App\Jobs\PushToCloud;
use App\Models\CloudFolder;
use App\Models\Faculty;
use App\Models\Submission;
use App\Services\Cloud\CloudStorageManager;
use App\Services\Dspace\DspaceClient;
use App\Services\Dspace\MetadataMapper;
use App\Services\SubmissionNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CloudAndDspaceTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function connectDrive(): void
    {
        $this->settings([
            'cloud.provider' => 'gdrive',
            'gdrive.client_id' => 'cid',
            'gdrive.client_secret' => 'csecret',
            'gdrive.refresh_token' => 'rtoken',
        ]);
    }

    public function test_push_to_google_drive_creates_folders_uploads_and_clears_staging(): void
    {
        $this->connectDrive();
        $created = 0;
        Http::fake(function (Request $r) use (&$created) {
            $url = $r->url();

            return match (true) {
                str_contains($url, 'oauth2.googleapis.com/token') => Http::response(['access_token' => 'at']),
                str_contains($url, '/upload/drive/v3/files?') => Http::response('', 200, ['Location' => 'https://upload.test/session']),
                $r->method() === 'GET' && str_contains($url, '/drive/v3/files?') => Http::response(['files' => []]),
                $r->method() === 'POST' && str_contains($url, '/drive/v3/files?') => Http::response(['id' => ++$created === 1 ? 'root-folder' : 'faculty-folder']),
                str_contains($url, 'upload.test/session') => Http::response(['id' => 'file-123']),
            };
        });
        $submission = $this->stagedSubmission(['status' => Submission::AI_ACCEPTED]);

        (new PushToCloud($submission->id))->handle(app(CloudStorageManager::class), $this->settings());

        $submission->refresh();
        $this->assertSame('gdrive', $submission->cloud_provider);
        $this->assertSame('file-123', $submission->cloud_file_id);
        $this->assertSame('Koleksi Skripsi/Fakultas Farmasi/2011012345_Siti_Aminah.pdf', $submission->cloud_path);
        $this->assertNull($submission->staging_path);
        $this->assertSame(2, CloudFolder::count());
        Http::assertSent(fn (Request $r) => $r->url() === 'https://upload.test/session'
            && $r->header('Content-Range')[0] === 'bytes 0-14/15');
    }

    public function test_push_waits_in_staging_when_no_provider_is_connected(): void
    {
        Http::fake();
        $submission = $this->stagedSubmission(['status' => Submission::AI_ACCEPTED]);

        (new PushToCloud($submission->id))->handle(app(CloudStorageManager::class), $this->settings());

        $this->assertNotNull($submission->fresh()->staging_path);
        Http::assertNothingSent();
    }

    public function test_metadata_mapping(): void
    {
        $submission = $this->stagedSubmission(['status' => Submission::APPROVED]);

        $metadata = app(MetadataMapper::class)->map($submission->load('faculty', 'studyProgram'));

        $this->assertSame('Uji Aktivitas Antioksidan Ekstrak Daun Gambir', $metadata['dc.title'][0]['value']);
        $this->assertSame(['Dr. A', 'Dr. B'], array_column($metadata['dc.contributor.advisor'], 'value'));
        $this->assertSame('2011012345', $metadata['dc.identifier.nim'][0]['value']);
        $this->assertSame('Thesis', $metadata['dc.type'][0]['value']);
        $this->assertSame(now()->format('Y'), $metadata['dc.date.issued'][0]['value']);
    }

    private function configureDspace(): string
    {
        $this->settings([
            'dspace.enabled' => true,
            'dspace.base_url' => 'https://repo.test/server',
            'dspace.email' => 'bot@unand.ac.id',
            'dspace.password' => 'pw',
        ]);
        $uuid = '11111111-2222-3333-4444-555555555555';
        Faculty::where('code', 'FFARMASI')->update(['dspace_collection_uuid' => $uuid]);

        return $uuid;
    }

    private function fakeDspace(): void
    {
        Http::fake([
            'repo.test/server/api/security/csrf' => Http::response('', 204, ['DSPACE-XSRF-TOKEN' => 'csrf-1']),
            'repo.test/server/api/authn/login' => Http::response('', 200, ['Authorization' => 'Bearer jwt-1', 'DSPACE-XSRF-TOKEN' => 'csrf-2']),
            'repo.test/server/api/core/items?*' => Http::response(['uuid' => 'item-1', 'handle' => '123456789/42'], 201),
            'repo.test/server/api/core/items/item-1/bundles' => Http::sequence()
                ->push(['_embedded' => ['bundles' => []]])
                ->push(['uuid' => 'bundle-1'], 201),
            'repo.test/server/api/core/bundles/bundle-1/bitstreams' => Http::response(['uuid' => 'bit-1'], 201),
        ]);
    }

    private function deposit(Submission $submission): void
    {
        (new DepositToDspace($submission->id))->handle(
            app(DspaceClient::class),
            app(MetadataMapper::class),
            app(CloudStorageManager::class),
            app(SubmissionNotifier::class),
        );
    }

    public function test_deposit_creates_archived_item_bundle_and_bitstream(): void
    {
        Queue::fake();
        $uuid = $this->configureDspace();
        $this->fakeDspace();
        $submission = $this->stagedSubmission(['status' => Submission::APPROVED]);

        $this->deposit($submission);

        $submission->refresh();
        $this->assertSame(Submission::DEPOSITED, $submission->status);
        $this->assertSame('item-1', $submission->dspace_item_uuid);
        $this->assertSame('123456789/42', $submission->dspace_handle);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'core/items?owningCollection='.$uuid)
            && $r['inArchive'] === true
            && $r->hasHeader('Authorization', 'Bearer jwt-1')
            && $r->hasHeader('X-XSRF-TOKEN', 'csrf-2'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'bundles/bundle-1/bitstreams') && $r->isMultipart());
        $this->assertDatabaseHas('activity_logs', ['action' => 'dspace.deposited', 'submission_id' => $submission->id]);
    }

    public function test_retry_does_not_duplicate_an_existing_item_or_bitstream(): void
    {
        Queue::fake();
        $this->configureDspace();
        Http::fake([
            'repo.test/server/api/security/csrf' => Http::response('', 204, ['DSPACE-XSRF-TOKEN' => 'c']),
            'repo.test/server/api/authn/login' => Http::response('', 200, ['Authorization' => 'Bearer j']),
            'repo.test/server/api/core/items/item-1/bundles' => Http::response(['_embedded' => ['bundles' => [['uuid' => 'bundle-1', 'name' => 'ORIGINAL']]]]),
            'repo.test/server/api/core/bundles/bundle-1/bitstreams' => Http::response(['page' => ['totalElements' => 1]]),
        ]);
        $submission = $this->stagedSubmission([
            'status' => Submission::DEPOSIT_FAILED, 'dspace_item_uuid' => 'item-1', 'dspace_handle' => '123456789/42',
        ]);

        $this->deposit($submission);

        $this->assertSame(Submission::DEPOSITED, $submission->fresh()->status);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), 'core/items'));
    }

    public function test_deposit_fails_clearly_without_collection_uuid(): void
    {
        $this->configureDspace();
        Faculty::query()->update(['dspace_collection_uuid' => null]);
        Http::fake();
        $submission = $this->stagedSubmission(['status' => Submission::APPROVED]);

        DepositToDspace::dispatchSync($submission->id);

        $submission->refresh();
        $this->assertSame(Submission::DEPOSIT_FAILED, $submission->status);
        $this->assertStringContainsString('UUID koleksi', $submission->last_error);
        Http::assertNothingSent();
    }
}
