<?php

namespace Tests\Feature;

use App\Jobs\SendLoginReply;
use App\Models\StudentLogin;
use App\Models\Submission;
use App\Services\StudentLogins;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class StudentLoginTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private const SECRET = 'webhook-secret-for-tests-123';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Cache::flush();
        $this->settings(['login.webhook_secret' => self::SECRET]);
    }

    /** POST a signed OpenWA delivery exactly like the gateway does (signature over the raw body). */
    private function webhook(array $message, string $event = 'message.received', ?string $key = null, ?string $secret = self::SECRET)
    {
        $raw = json_encode(['event' => $event, 'sessionId' => 's1', 'idempotencyKey' => $key ?? 'k'.uniqid(), 'data' => $message]);
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if ($secret !== null) {
            $server['HTTP_X_OPENWA_SIGNATURE'] = 'sha256='.hash_hmac('sha256', $raw, $secret);
        }

        return $this->call('POST', '/api/wa/webhook', [], [], [], $server, $raw);
    }

    private function incoming(string $body, array $extra = []): array
    {
        return $extra + ['id' => 'm1', 'chatId' => '6281234567890@c.us', 'from' => '6281234567890@c.us', 'body' => $body,
            'type' => 'text', 'fromMe' => false, 'isGroup' => false, 'kind' => 'individual'];
    }

    public function test_only_kliring_messages_are_recognised(): void
    {
        $this->assertSame(['nim' => '2011012345'], StudentLogins::parse('kliring_2011012345'));
        $this->assertSame(['nim' => '2011012345'], StudentLogins::parse("  Kliring_2011012345 \n"));
        $this->assertSame(['nim' => '2011012345'], StudentLogins::parse('KLIRING 2011012345'));
        $this->assertSame(['help' => true], StudentLogins::parse('kliring'));
        $this->assertSame(['help' => true], StudentLogins::parse('kliring_20110 12345 tolong'));
        $this->assertNull(StudentLogins::parse('halo kak, kliring saya belum disetujui'));
        $this->assertNull(StudentLogins::parse('kliringlib'));
        $this->assertNull(StudentLogins::parse('terima kasih'));
    }

    public function test_the_webhook_refuses_unsigned_or_wrongly_signed_requests(): void
    {
        $this->webhook($this->incoming('kliring_2011012345'), secret: null)->assertUnauthorized();
        $this->webhook($this->incoming('kliring_2011012345'), secret: 'another-secret-entirely-1')->assertUnauthorized();

        $this->assertSame(0, StudentLogin::count());
        Queue::assertNothingPushed();
    }

    public function test_a_kliring_message_gets_a_one_time_code_back_on_whatsapp(): void
    {
        $this->webhook($this->incoming('kliring_2011012345'))->assertOk()->assertJson(['result' => 'issued']);

        $login = StudentLogin::firstOrFail();
        $this->assertSame('2011012345', $login->nim);
        $this->assertSame('6281234567890', $login->phone);
        $this->assertNull($login->used_at);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $login->code_hash, 'only a hash of the code is stored');
        Queue::assertPushedOn('notify', SendLoginReply::class, function (SendLoginReply $job) {
            return $job->chatId === '6281234567890@c.us' && preg_match('/\b[A-Z0-9]{4}-[A-Z0-9]{4}\b/', $job->message)
                && str_contains($job->message, '2011012345') && str_contains($job->message, '?kode=');
        });
    }

    public function test_other_messages_groups_and_own_messages_are_never_answered(): void
    {
        $this->webhook($this->incoming('terima kasih kak'))->assertOk();
        $this->webhook($this->incoming('kliring_2011012345', ['fromMe' => true]))->assertOk();
        $this->webhook($this->incoming('kliring_2011012345', ['isGroup' => true, 'kind' => 'group', 'chatId' => '123@g.us']))->assertOk();
        $this->webhook($this->incoming('kliring_2011012345', ['type' => 'image']))->assertOk();
        $this->webhook(['status' => 'x'], event: 'session.status')->assertOk();
        $this->webhook([], event: 'test')->assertOk();

        $this->assertSame(0, StudentLogin::count());
        Queue::assertNothingPushed();
    }

    public function test_a_malformed_kliring_message_gets_the_help_text(): void
    {
        $this->webhook($this->incoming('kliring'))->assertOk()->assertJson(['result' => 'help']);

        $this->assertSame(0, StudentLogin::count());
        Queue::assertPushed(SendLoginReply::class, fn ($job) => str_contains($job->message, 'kliring_NIM'));
    }

    public function test_a_redelivered_event_is_processed_once(): void
    {
        $this->webhook($this->incoming('kliring_2011012345'), key: 'same')->assertOk()->assertJson(['result' => 'issued']);
        $this->webhook($this->incoming('kliring_2011012345'), key: 'same')->assertOk()->assertJson(['ignored' => 'duplicate']);

        $this->assertSame(1, StudentLogin::count());
        Queue::assertPushed(SendLoginReply::class, 1);
    }

    public function test_a_number_can_ask_for_five_codes_an_hour_then_gets_one_notice_and_silence(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->webhook($this->incoming('kliring_2011012345'))->assertJson(['result' => 'issued']);
        }
        $this->webhook($this->incoming('kliring_2011012345'))->assertJson(['result' => 'limited']);
        $this->webhook($this->incoming('kliring_2011012345'))->assertJson(['result' => 'limited']);

        $this->assertSame(5, StudentLogin::count());
        Queue::assertPushed(SendLoginReply::class, 6); // five codes + a single "too many requests" notice
    }

    public function test_a_new_code_cancels_the_previous_unused_one_of_the_same_number(): void
    {
        $logins = app(StudentLogins::class);
        $first = $logins->issue('2011012345', '6281234567890');
        $second = $logins->issue('2011012345', '6281234567890');

        $this->assertNull($logins->redeem($first));
        $this->assertNotNull($logins->redeem($second));
    }

    public function test_a_nim_that_cannot_upload_gets_the_reason_instead_of_a_code(): void
    {
        $this->stagedSubmission(['nim' => '2011012345', 'status' => Submission::AI_ACCEPTED]);

        $this->webhook($this->incoming('kliring_2011012345'))->assertOk()->assertJson(['result' => 'refused']);

        $this->assertSame(0, StudentLogin::count());
        Queue::assertPushed(SendLoginReply::class, fn ($job) => str_contains($job->message, 'belum bisa mengunggah'));
    }

    public function test_the_number_behind_a_privacy_id_is_looked_up(): void
    {
        $this->settings(['wa.openwa_url' => 'https://owa.test', 'wa.openwa_key' => 'k', 'wa.openwa_session' => 's1']);
        Http::fake(['owa.test/*' => Http::response(['contactId' => '99@lid', 'phone' => '6285111222333'])]);

        $this->webhook($this->incoming('kliring_2011012345', ['chatId' => '99@lid', 'from' => '99@lid']))->assertJson(['result' => 'issued']);

        $this->assertSame('6285111222333', StudentLogin::firstOrFail()->phone);
        Queue::assertPushed(SendLoginReply::class, fn ($job) => $job->chatId === '99@lid');
    }

    public function test_a_code_is_traded_for_a_session_once(): void
    {
        $code = app(StudentLogins::class)->issue('2011012345', '6281234567890');

        $token = $this->postJson('/api/login', ['code' => strtolower(substr($code, 0, 4).'-'.substr($code, 4))])
            ->assertOk()->assertJson(['nim' => '2011012345', 'phone' => '081234567890'])->json('token');
        $this->assertSame(48, strlen($token));

        $this->postJson('/api/login', ['code' => $code])->assertStatus(422); // used
        $this->getJson('/api/me', ['Authorization' => 'Bearer '.$token])->assertOk()->assertJson(['nim' => '2011012345']);
        $this->getJson('/api/me')->assertUnauthorized();
        $this->getJson('/api/me', ['Authorization' => 'Bearer '.str_repeat('x', 48)])->assertUnauthorized();

        $this->postJson('/api/logout', [], ['Authorization' => 'Bearer '.$token])->assertOk();
        $this->getJson('/api/me', ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
    }

    public function test_expired_and_wrong_codes_are_refused(): void
    {
        $logins = app(StudentLogins::class);
        $code = $logins->issue('2011012345', '6281234567890');
        StudentLogin::query()->update(['code_expires_at' => now()->subMinute()]);

        $this->postJson('/api/login', ['code' => $code])->assertStatus(422);
        $this->postJson('/api/login', ['code' => 'ZZZZ-ZZZZ'])->assertStatus(422);
        $this->postJson('/api/login', ['code' => ''])->assertStatus(422);
    }

    public function test_a_session_expires(): void
    {
        $logins = app(StudentLogins::class);
        $token = $logins->redeem($logins->issue('2011012345', '6281234567890'))['token'];
        StudentLogin::query()->update(['session_expires_at' => now()->subMinute()]);

        $this->getJson('/api/me', ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
    }

    public function test_uploads_stay_open_until_login_is_switched_on(): void
    {
        $this->settings(['login.enabled' => false]);

        $this->postJson('/api/submissions', $this->uploadPayload())->assertCreated();
        $this->get('/')->assertOk()->assertViewHas('schema', fn ($schema) => $schema['login']['required'] === false);
    }

    public function test_with_login_on_an_upload_needs_a_session_and_is_filed_under_the_logged_in_student(): void
    {
        $this->settings(['login.enabled' => true, 'login.wa_number' => '6281200000000']);
        $this->get('/')->assertOk()->assertViewHas('schema', fn ($schema) => $schema['login']['required'] === true
            && $schema['login']['wa_link'] === 'https://wa.me/6281200000000?text=kliring_');

        $this->postJson('/api/submissions', $this->uploadPayload())->assertUnauthorized()->assertJson(['login_required' => true]);
        $this->assertSame(0, Submission::count());

        $logins = app(StudentLogins::class);
        $token = $logins->redeem($logins->issue('2099000001', '6285700000001'))['token'];
        // The form says another NIM and number: the session wins.
        $this->postJson('/api/submissions', $this->uploadPayload(['nim' => '2011012345', 'no_wa' => '081200000009']), ['Authorization' => 'Bearer '.$token])->assertCreated();

        $submission = Submission::firstOrFail();
        $this->assertSame('2099000001', $submission->nim);
        $this->assertSame('085700000001', $submission->no_wa);
    }

    public function test_an_expired_session_is_refused_at_upload(): void
    {
        $this->settings(['login.enabled' => true]);
        $logins = app(StudentLogins::class);
        $token = $logins->redeem($logins->issue('2099000001', '6285700000001'))['token'];
        StudentLogin::query()->update(['session_expires_at' => now()->subSecond()]);

        $this->postJson('/api/submissions', $this->uploadPayload(), ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
    }

    public function test_expired_rows_are_purged(): void
    {
        $logins = app(StudentLogins::class);
        $logins->issue('2011012345', '6281234567890');
        StudentLogin::query()->update(['code_expires_at' => now()->subDays(3)]);
        $fresh = $logins->issue('2011012346', '6281234567891');

        $this->assertSame(1, $logins->purge());
        $this->assertNotNull($logins->redeem($fresh));
    }
}
