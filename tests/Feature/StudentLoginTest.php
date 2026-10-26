<?php

namespace Tests\Feature;

use App\Jobs\SendLoginReply;
use App\Livewire\Admin\Settings\WhatsApp;
use App\Models\StudentLogin;
use App\Models\Submission;
use App\Models\User;
use App\Services\StudentLogins;
use App\Services\WhatsApp\LoginInbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class StudentLoginTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Cache::flush();
        $this->settings(['wa.openwa_url' => 'https://owa.test', 'wa.openwa_key' => 'k', 'wa.openwa_session' => 's1', 'login.listen' => true]);
    }

    /** A message as OpenWA stores it. */
    private function stored(string $body, array $extra = []): array
    {
        return $extra + ['id' => (string) Str::uuid(), 'chatId' => '6281234567890@c.us', 'from' => '6281234567890', 'body' => $body,
            'type' => 'text', 'direction' => 'incoming', 'timestamp' => now()->getTimestamp()];
    }

    /** Let the inbox of the library number hold these messages, then look at it once. */
    private function poll(array $messages, array $other = []): int
    {
        Http::fake($other + ['owa.test/api/sessions/s1/messages*' => Http::response(['messages' => $messages, 'total' => count($messages)])]);

        return app(LoginInbox::class)->poll();
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

    public function test_the_inbox_is_read_from_openwa_incoming_text_only(): void
    {
        $this->poll([]);

        Http::assertSent(fn ($r) => $r->hasHeader('X-API-Key', 'k') && str_contains($r->url(), '/api/sessions/s1/messages')
            && $r['direction'] === 'incoming' && $r['type'] === 'text' && (int) $r['since'] > 0);
    }

    public function test_a_kliring_message_gets_a_one_time_code_back_on_whatsapp(): void
    {
        $this->assertSame(1, $this->poll([$this->stored('kliring_2011012345')]));

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

    public function test_other_messages_groups_old_messages_and_media_are_never_answered(): void
    {
        $this->poll([
            $this->stored('terima kasih kak'),
            $this->stored('kliring_2011012345', ['chatId' => '123@g.us', 'from' => '6281234567890']),
            $this->stored('kliring_2011012345', ['type' => 'image']),
            $this->stored('kliring_2011012345', ['timestamp' => now()->subHour()->getTimestamp()]),
        ]);

        $this->assertSame(0, StudentLogin::count());
        Queue::assertNothingPushed();
    }

    public function test_a_malformed_kliring_message_gets_the_help_text(): void
    {
        $this->poll([$this->stored('kliring')]);

        $this->assertSame(0, StudentLogin::count());
        Queue::assertPushed(SendLoginReply::class, fn ($job) => str_contains($job->message, 'kliring_NIM'));
    }

    public function test_a_message_seen_twice_is_answered_once_and_only_new_ones_are_asked_for(): void
    {
        $message = $this->stored('kliring_2011012345');
        $this->poll([$message]);
        $this->poll([$message]); // the window includes the second of the last message, so it comes back

        $this->assertSame(1, StudentLogin::count());
        Queue::assertPushed(SendLoginReply::class, 1);
        $this->assertSame($message['timestamp'] * 1000, (int) $this->settings()->get('login.poll_cursor'));

        $this->poll([]);
        Http::assertSent(fn ($r) => (int) $r['since'] === $message['timestamp'] * 1000);
    }

    public function test_a_number_can_ask_for_five_codes_an_hour_then_gets_one_notice_and_silence(): void
    {
        $this->poll(array_map(fn () => $this->stored('kliring_2011012345'), range(1, 8)));

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

        $this->poll([$this->stored('kliring_2011012345')]);

        $this->assertSame(0, StudentLogin::count());
        Queue::assertPushed(SendLoginReply::class, fn ($job) => str_contains($job->message, 'belum bisa mengunggah'));
    }

    public function test_the_number_behind_a_privacy_id_is_looked_up(): void
    {
        $this->poll([$this->stored('kliring_2011012345', ['chatId' => '99@lid', 'from' => '99'])],
            ['owa.test/api/sessions/s1/contacts/*' => Http::response(['contactId' => '99@lid', 'phone' => '6285111222333'])]);

        $this->assertSame('6285111222333', StudentLogin::firstOrFail()->phone);
        Queue::assertPushed(SendLoginReply::class, fn ($job) => $job->chatId === '6285111222333@c.us', 'the reply goes to the real number, not the privacy id');
    }

    public function test_when_the_number_cannot_be_resolved_the_reply_still_goes_to_the_chat_and_the_form_keeps_the_number_field_open(): void
    {
        $this->poll([$this->stored('kliring_2011012345', ['chatId' => '99@lid', 'from' => '99'])],
            ['owa.test/api/sessions/s1/contacts/*' => Http::response(['contactId' => '99@lid', 'phone' => null])]);

        $login = StudentLogin::firstOrFail();
        $this->assertNull($login->phone);
        $this->assertNull($login->localPhone());
        Queue::assertPushed(SendLoginReply::class, fn ($job) => $job->chatId === '99@lid');
    }

    public function test_the_inbox_only_runs_when_the_service_is_on(): void
    {
        $this->settings(['login.listen' => false, 'login.enabled' => false]);
        $this->assertFalse(app(LoginInbox::class)->active());

        $this->settings(['login.listen' => true]);
        $this->assertTrue(app(LoginInbox::class)->active());

        $this->settings(['login.listen' => false, 'login.enabled' => true]);
        $this->assertTrue(app(LoginInbox::class)->active(), 'requiring login implies answering the code requests');

        $this->settings(['wa.openwa_session' => null]);
        $this->assertFalse(app(LoginInbox::class)->active());
    }

    public function test_a_gateway_error_is_remembered_for_the_admin_page_and_does_not_crash(): void
    {
        Http::fake(['owa.test/*' => Http::response(['message' => 'boom'], 500)]);

        $this->assertSame(0, app(LoginInbox::class)->poll());
        $this->assertStringContainsString('500', (string) Cache::get(LoginInbox::LAST_ERROR_KEY));
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

    public function test_the_admin_page_saves_the_login_options_and_shows_the_inbox_state(): void
    {
        $this->actingAs(User::create(['name' => 'A', 'email' => 'a@t.id', 'password' => 'password-123456', 'role' => 'superadmin', 'is_active' => true]));
        Http::fake(['owa.test/*' => Http::response(['id' => 's1', 'status' => 'ready', 'phone' => '6281200000000'])]);

        Livewire::test(WhatsApp::class)
            ->assertSee('Login mahasiswa lewat WhatsApp')
            ->set('form.login__listen', true)->set('form.login__enabled', true)
            ->set('form.login__wa_number', '0812-0000-0000')->set('form.login__code_minutes', 20)
            ->call('save')->assertHasNoErrors()
            ->set('form.login__wa_number', 'abc')->call('save')->assertHasErrors('form.login__wa_number');

        $settings = $this->settings();
        $this->assertTrue($settings->bool('login.listen'));
        $this->assertTrue($settings->bool('login.enabled'));
        $this->assertSame('6281200000000', $settings->get('login.wa_number'));
        $this->assertSame(20, $settings->int('login.code_minutes'));
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
