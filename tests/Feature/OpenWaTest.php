<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsApp;
use App\Livewire\Admin\Settings\WhatsApp;
use App\Models\Submission;
use App\Models\User;
use App\Services\WhatsApp\OpenWaClient;
use App\Services\WhatsApp\WhatsAppSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class OpenWaTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private const BASE = 'http://openwa.test:2785';

    private const QR = 'data:image/png;base64,iVBORw0KGgo=';

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings(['wa.openwa_url' => self::BASE, 'wa.openwa_key' => 'owa_k1_secret']);
    }

    private function admin(): User
    {
        return User::create(['name' => 'Admin', 'email' => 'wa@test.id', 'password' => 'password-123456', 'role' => 'superadmin', 'is_active' => true]);
    }

    private function owaSession(string $status, array $extra = []): array
    {
        return array_merge(['id' => 'sess-1', 'name' => 'uploader', 'status' => $status, 'phone' => null, 'pushName' => null], $extra);
    }

    public function test_connect_creates_the_session_then_starts_it_and_remembers_the_id(): void
    {
        Http::fake([
            self::BASE.'/api/sessions' => fn (Request $r) => $r->method() === 'GET'
                ? Http::response([])
                : Http::response($this->owaSession('created'), 201),
            self::BASE.'/api/sessions/sess-1/start' => Http::response($this->owaSession('initializing')),
        ]);

        app(OpenWaClient::class)->start();

        $this->assertSame('sess-1', $this->settings()->get('wa.openwa_session'));
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === self::BASE.'/api/sessions'
            && $r['name'] === 'uploader' && $r->header('X-API-Key')[0] === 'owa_k1_secret');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sessions/sess-1/start'));
    }

    public function test_an_existing_session_is_reused_and_already_started_is_fine(): void
    {
        Http::fake([
            self::BASE.'/api/sessions' => Http::response([['id' => 'other', 'name' => 'umum'], $this->owaSession('ready')]),
            self::BASE.'/api/sessions/sess-1/start' => Http::response(['message' => 'already started'], 400),
        ]);

        app(OpenWaClient::class)->start();

        $this->assertSame('sess-1', $this->settings()->get('wa.openwa_session'));
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === self::BASE.'/api/sessions');
    }

    public function test_qr_is_only_returned_as_a_png_data_url(): void
    {
        $this->settings(['wa.openwa_session' => 'sess-1']);
        Http::fakeSequence(self::BASE.'/api/sessions/sess-1/qr')
            ->push(['qrCode' => self::QR, 'status' => 'qr_ready'])
            ->push(['message' => 'QR not ready'], 400)
            ->push(['qrCode' => 'raw-text', 'status' => 'qr_ready']);
        $client = app(OpenWaClient::class);

        $this->assertSame(self::QR, $client->qr());
        $this->assertNull($client->qr());
        $this->assertNull($client->qr());
    }

    public function test_send_text_uses_the_c_us_chat_id_of_the_normalised_number(): void
    {
        $this->settings(['wa.openwa_session' => 'sess-1', 'wa.enabled' => true]);
        Http::fake([self::BASE.'/api/sessions/sess-1/messages/send-text' => Http::response(['messageId' => 'm1'], 201)]);

        $sender = app(WhatsAppSender::class);
        $this->assertTrue($sender->isConfigured());
        $sender->send('0812-3456-7890', "Halo\nDunia");

        Http::assertSent(fn (Request $r) => $r['chatId'] === '6281234567890@c.us' && $r['text'] === "Halo\nDunia"
            && $r->header('X-API-Key')[0] === 'owa_k1_secret');
    }

    public function test_failed_sends_raise_so_the_job_retries(): void
    {
        $this->settings(['wa.openwa_session' => 'sess-1', 'wa.enabled' => true]);
        Http::fake([self::BASE.'/api/sessions/sess-1/messages/send-text' => Http::response(['message' => 'Session not ready'], 400)]);
        $submission = $this->stagedSubmission();

        $this->expectExceptionMessage('Session not ready');
        (new SendWhatsApp($submission->id, 'ai_accepted', 'Halo'))->handle(app(WhatsAppSender::class));
    }

    public function test_custom_driver_keeps_using_the_generic_gateway(): void
    {
        $this->settings(['wa.openwa_session' => 'sess-1', 'wa.enabled' => true, 'wa.driver' => 'custom',
            'wa.url' => 'https://wa.test/send', 'wa.body_format' => 'json']);
        Http::fake(['https://wa.test/send' => Http::response('ok')]);

        app(WhatsAppSender::class)->send('08123456789', 'Halo');

        Http::assertSent(fn (Request $r) => $r->url() === 'https://wa.test/send' && $r['target'] === '628123456789');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'openwa.test'));
    }

    public function test_page_shows_the_qr_and_polls_quickly_while_waiting_for_the_scan(): void
    {
        $this->settings(['wa.openwa_session' => 'sess-1']);
        Http::fake([
            self::BASE.'/api/sessions/sess-1' => Http::response($this->owaSession('qr_ready')),
            self::BASE.'/api/sessions/sess-1/qr' => Http::response(['qrCode' => self::QR, 'status' => 'qr_ready']),
        ]);

        Livewire::actingAs($this->admin())->test(WhatsApp::class)
            ->assertSee('Menunggu scan QR')->assertSeeHtml(self::QR)->assertSee('Perangkat tertaut')
            ->assertSeeHtml('wire:poll.3s="refresh"');
    }

    public function test_a_session_that_was_created_but_not_started_offers_the_connect_button(): void
    {
        $this->settings(['wa.openwa_session' => 'sess-1']);
        Http::fake([self::BASE.'/api/sessions/sess-1' => Http::response($this->owaSession('created'))]);

        Livewire::actingAs($this->admin())->test(WhatsApp::class)
            ->assertSee('Hubungkan nomor WhatsApp')->assertDontSee('Mohon tunggu')->assertSeeHtml('wire:poll.30s="refresh"');
    }

    public function test_page_shows_the_linked_number_once_ready_and_can_unlink_it(): void
    {
        $this->settings(['wa.openwa_session' => 'sess-1']);
        Http::fake([
            self::BASE.'/api/sessions/sess-1/logout' => Http::response($this->owaSession('disconnected')),
            self::BASE.'/api/sessions/sess-1' => Http::sequence()
                ->push($this->owaSession('ready', ['phone' => '6281200000001', 'pushName' => 'Perpustakaan']))
                ->push($this->owaSession('disconnected')),
        ]);

        Livewire::actingAs($this->admin())->test(WhatsApp::class)
            ->assertSee('Tersambung')->assertSee('Perpustakaan')->assertSee('+6281200000001')
            ->call('disconnect')->assertSee('Hubungkan nomor WhatsApp')->assertDontSee('+6281200000001');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sessions/sess-1/logout'));
    }

    public function test_connect_button_starts_the_session_and_unreachable_gateway_is_reported(): void
    {
        Http::fake([self::BASE.'/*' => Http::response(['message' => 'boom'], 500)]);

        Livewire::actingAs($this->admin())->test(WhatsApp::class)
            ->call('connect')->assertSee('OpenWA daftar sesi gagal (HTTP 500)');
    }

    public function test_connection_can_be_saved_from_the_page_without_echoing_the_key(): void
    {
        $this->settings(['wa.openwa_url' => null, 'wa.openwa_key' => null]);
        config(['services.openwa' => ['url' => null, 'key' => null, 'session' => 'uploader']]);
        Http::fake();

        Livewire::actingAs($this->admin())->test(WhatsApp::class)
            ->assertSee('Isi alamat server OpenWA')
            ->set('form.wa__openwa_url', 'not a url')->call('saveConnection')->assertHasErrors('form.wa__openwa_url')
            ->set('form.wa__openwa_url', 'http://openwa:2785/')->set('form.wa__openwa_key', 'owa_k1_new')
            ->call('saveConnection')->assertHasNoErrors()->assertSet('form.wa__openwa_key', '');

        $this->assertSame('http://openwa:2785', $this->settings()->get('wa.openwa_url'));
        $this->assertSame('owa_k1_new', $this->settings()->get('wa.openwa_key'));
        $this->assertDatabaseMissing('settings', ['key' => 'wa.openwa_key', 'value' => json_encode('owa_k1_new')]); // stored encrypted
    }

    public function test_test_message_requires_a_connected_number(): void
    {
        $this->settings(['wa.openwa_session' => 'sess-1']);
        Http::fake([self::BASE.'/api/sessions/sess-1' => Http::response($this->owaSession('qr_ready')),
            self::BASE.'/api/sessions/sess-1/qr' => Http::response(['qrCode' => self::QR])]);

        Livewire::actingAs($this->admin())->test(WhatsApp::class)
            ->set('testPhone', '08123456789')->call('sendTest')->assertSee('belum tersambung');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'send-text'));
    }

    public function test_missing_session_on_the_gateway_is_forgotten_so_connect_recreates_it(): void
    {
        $this->settings(['wa.openwa_session' => 'gone']);
        Http::fake([self::BASE.'/api/sessions/gone' => Http::response(['message' => 'not found'], 404)]);

        $this->assertNull(app(OpenWaClient::class)->session());
        $this->assertNull($this->settings()->get('wa.openwa_session'));
    }

    public function test_upload_notification_goes_through_openwa_end_to_end(): void
    {
        $this->settings(['wa.openwa_session' => 'sess-1', 'wa.enabled' => true]);
        Http::fake([self::BASE.'/api/sessions/sess-1/messages/send-text' => Http::response(['messageId' => 'm'], 201)]);
        $submission = $this->stagedSubmission(['status' => Submission::AI_ACCEPTED, 'no_wa' => '081234567890']);

        app(\App\Services\SubmissionNotifier::class)->notify($submission, 'admin_approved');

        Http::assertSent(fn (Request $r) => $r['chatId'] === '6281234567890@c.us' && str_contains($r['text'], 'DIVERIFIKASI'));
    }
}
