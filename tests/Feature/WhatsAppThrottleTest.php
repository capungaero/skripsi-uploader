<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsApp;
use App\Livewire\Admin\Settings\WhatsApp;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\WhatsApp\WhatsAppThrottle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class WhatsAppThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private const SEND = 'http://openwa.test/api/sessions/sess-1/messages/send-text';

    private int $gatewayStatus = 201;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->settings([
            'wa.enabled' => true, 'wa.openwa_url' => 'http://openwa.test', 'wa.openwa_key' => 'k', 'wa.openwa_session' => 'sess-1',
            'wa.interval_min' => 0, 'wa.interval_max' => 0, 'wa.max_per_hour' => 0, 'wa.max_per_day' => 0,
        ]);
        Http::fake([self::SEND => fn () => Http::response(['messageId' => 'm'], $this->gatewayStatus)]);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function run_(int $submissionId): void
    {
        (new SendWhatsApp($submissionId, 'ai_accepted', 'Halo'))->handle(app(\App\Services\WhatsApp\WhatsAppSender::class));
    }

    private function sentCount(): int
    {
        return count(Http::recorded(fn (Request $r) => $r->url() === self::SEND));
    }

    private function deferredPushes(): int
    {
        return Queue::pushed(SendWhatsApp::class)->count();
    }

    public function test_no_limits_means_every_message_goes_out_immediately(): void
    {
        $id = $this->stagedSubmission()->id;

        $this->run_($id);
        $this->run_($id);

        $this->assertSame(2, $this->sentCount());
        $this->assertSame(0, $this->deferredPushes());
    }

    public function test_pause_between_messages_defers_the_next_one_into_a_fresh_job(): void
    {
        $this->settings(['wa.interval_min' => 20, 'wa.interval_max' => 20]);
        $id = $this->stagedSubmission()->id;

        $this->run_($id);
        $this->run_($id);

        $this->assertSame(1, $this->sentCount());
        $this->assertSame(1, $this->deferredPushes());
        Queue::assertPushed(SendWhatsApp::class, function ($job) {
            $delay = $job->delay->diffInSeconds(now(), true);

            return $delay >= 15 && $delay <= 70; // ~20s pause plus a little jitter
        });

        // once the pause is over the same message goes out
        Carbon::setTestNow(now()->addSeconds(25));
        $this->run_($id);
        $this->assertSame(2, $this->sentCount());
    }

    public function test_hourly_cap_holds_messages_until_the_oldest_send_ages_out(): void
    {
        $this->settings(['wa.max_per_hour' => 2]);
        $id = $this->stagedSubmission()->id;
        ActivityLog::create(['actor_type' => 'system', 'action' => 'wa.sent', 'created_at' => now()->subMinutes(50)]);
        ActivityLog::create(['actor_type' => 'system', 'action' => 'wa.sent', 'created_at' => now()->subMinutes(10)]);

        $wait = app(WhatsAppThrottle::class)->waitSeconds();
        $this->assertGreaterThanOrEqual(9 * 60, $wait); // the 50-minute-old send leaves the window in ~10 minutes
        $this->assertLessThanOrEqual(10 * 60 + 5, $wait);

        $this->run_($id);
        $this->assertSame(0, $this->sentCount());
        $this->assertSame(1, $this->deferredPushes());

        Carbon::setTestNow(now()->addMinutes(11));
        $this->run_($id);
        $this->assertSame(1, $this->sentCount());
    }

    public function test_daily_cap_counts_test_messages_too(): void
    {
        $this->settings(['wa.max_per_day' => 2]);
        ActivityLog::create(['actor_type' => 'system', 'action' => 'wa.sent', 'created_at' => now()->subHours(20)]);
        ActivityLog::create(['actor_type' => 'admin', 'action' => 'wa.tested', 'created_at' => now()->subHours(1)]);

        $wait = app(WhatsAppThrottle::class)->waitSeconds();

        $this->assertGreaterThan(3.9 * 3600, $wait); // waits for the 20-hour-old send to pass 24 hours
        $this->assertLessThan(4.1 * 3600, $wait);
    }

    public function test_sending_window_holds_messages_outside_the_hours(): void
    {
        $this->settings(['wa.window_start' => '07:00', 'wa.window_end' => '21:00']);
        $tz = config('skripsi.timezone');
        $throttle = app(WhatsAppThrottle::class);

        Carbon::setTestNow(Carbon::parse('2026-10-15 12:00', $tz)); // inside
        $this->assertSame(0, $throttle->waitSeconds());

        Carbon::setTestNow(Carbon::parse('2026-10-15 23:00', $tz)); // after closing: wait until 07:00 tomorrow = 8h
        $this->assertSame(8 * 3600, $throttle->waitSeconds());

        Carbon::setTestNow(Carbon::parse('2026-10-15 05:30', $tz)); // before opening: 1.5h
        $this->assertSame(90 * 60, $throttle->waitSeconds());
    }

    public function test_overnight_window_is_supported(): void
    {
        $this->settings(['wa.window_start' => '22:00', 'wa.window_end' => '06:00']);
        $tz = config('skripsi.timezone');
        $throttle = app(WhatsAppThrottle::class);

        Carbon::setTestNow(Carbon::parse('2026-10-15 23:30', $tz));
        $this->assertSame(0, $throttle->waitSeconds());
        Carbon::setTestNow(Carbon::parse('2026-10-15 03:00', $tz));
        $this->assertSame(0, $throttle->waitSeconds());
        Carbon::setTestNow(Carbon::parse('2026-10-15 12:00', $tz));
        $this->assertSame(10 * 3600, $throttle->waitSeconds());
    }

    public function test_a_failed_send_does_not_start_a_pause(): void
    {
        $this->settings(['wa.interval_min' => 60, 'wa.interval_max' => 60]);
        $this->gatewayStatus = 500;
        $id = $this->stagedSubmission()->id;

        try {
            $this->run_($id);
        } catch (\Throwable) {
        }

        $this->assertSame(0, app(WhatsAppThrottle::class)->waitSeconds());
        $this->assertSame(0, ActivityLog::where('action', 'wa.sent')->count());
    }

    public function test_page_saves_limits_validates_them_and_offers_suggested_values(): void
    {
        $admin = User::create(['name' => 'A', 'email' => 'a@t.id', 'password' => 'password-123456', 'role' => 'superadmin', 'is_active' => true]);
        ActivityLogger::system('wa.sent');
        Http::fake(['*' => Http::response(['status' => 'ready', 'id' => 'sess-1'])]);

        Livewire::actingAs($admin)->test(WhatsApp::class)
            ->assertSee('Batas pengiriman')->assertSee('Terkirim 1 jam terakhir')
            ->call('applySuggested')
            ->assertSet('form.wa__interval_min', 15)->assertSet('form.wa__max_per_day', 200)->assertSet('form.wa__window_start', '07:00')
            ->set('form.wa__interval_min', 30)->set('form.wa__interval_max', 10)->call('save')->assertHasErrors('form.wa__interval_max')
            ->set('form.wa__interval_max', 45)->set('form.wa__window_end', '')->call('save')->assertHasErrors('form.wa__window_end')
            ->set('form.wa__window_end', '21:00')->call('save')->assertHasNoErrors();

        $s = $this->settings();
        $this->assertEquals(30, $s->get('wa.interval_min'));
        $this->assertEquals(45, $s->get('wa.interval_max'));
        $this->assertSame('07:00', $s->get('wa.window_start'));
        $this->assertEquals(30, $s->get('wa.max_per_hour')); // from the suggested values
    }
}
