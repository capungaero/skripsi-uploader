<?php

namespace Tests\Feature;

use App\Jobs\ApproveInKliring;
use App\Livewire\Admin\Settings\Kliring;
use App\Models\Submission;
use App\Models\User;
use App\Services\Kliring\KliringClient;
use App\Services\SubmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/** HTML fixtures follow the markup of kliringlib.unand.ac.id (Yii2 + AdminLTE). */
class KliringTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private const NIM = '2011012345';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.kliring' => ['url' => 'https://kliring.test', 'username' => 'admin', 'password' => 'rahasia']]);
    }

    private function connectSession(): void
    {
        $this->settings(['kliring.session' => [[
            'Name' => 'PHPSESSID', 'Value' => 'sess1', 'Domain' => 'kliring.test', 'Path' => '/',
            'Max-Age' => null, 'Expires' => null, 'Secure' => false, 'Discard' => false, 'HttpOnly' => true,
        ]]]);
    }

    private function admin(): User
    {
        return User::create(['name' => 'Admin', 'email' => 'admin@test.id', 'password' => 'password-123456',
            'role' => 'superadmin', 'is_active' => true]);
    }

    private function listPage(array $rows, string $view = 'view'): string
    {
        $html = '';
        foreach ($rows as [$nim, $status, $param]) {
            $html .= "<tr><td>1</td><td><b>SITI AMINAH</b><br><small>ID : {$nim}</small></td><td>Farmasi</td>"
                ."<td><span class=\"label label-default\">{$status}</span></td>"
                ."<td><a href=\"/kelola/kliring/{$view}?param={$param}\" title=\"Detail Permohonan Kliring\">x</a></td></tr>";
        }

        return "<html><body><table class=\"table\"><thead><tr><th>Nama</th></tr></thead><tbody>{$html}</tbody></table></body></html>";
    }

    private function viewPage(string $status, string $karyaValid = '-'): string
    {
        return '<html><head><meta name="csrf-token" content="csrf-xyz"></head><body>'
            .'<table><tr><td>Nama</td><td>:</td><td>SITI AMINAH</td></tr>'
            ."<tr><td>Status</td><td>:</td><td><span class=\"label\">{$status}</span></td></tr></table>"
            .'<table><thead><tr><th>No</th><th>Item</th><th>Catatan</th><th>Valid</th><th>Aksi</th></tr></thead><tbody>'
            .'<tr><td>1</td><td>Bebas Peminjaman Buku</td><td></td><td>Ya</td><td><a href="#validasi" onclick="dialogValidasi(\'1P\')">v</a></td></tr>'
            ."<tr><td>2</td><td>Mengunggah karya ilmiah di https://karyailmiah.pustaka.unand.ac.id</td><td></td><td>{$karyaValid}</td>"
            .'<td><a href="#validasi" onclick="dialogValidasi(\'2P\')">v</a></td></tr>'
            .'</tbody></table><a href="#bebaskan" onclick="terimaPermohonan()">Terima Permohonan</a></body></html>';
    }

    /** Pending request in "Permohonan"; accepting it flips the detail page to "Bebas". */
    private function fakeSite(array $pending, array $history = [], bool $acceptWorks = true): void
    {
        $accepted = false;
        Http::fake(function (Request $r) use ($pending, $history, $acceptWorks, &$accepted) {
            $path = parse_url($r->url(), PHP_URL_PATH);

            return match (true) {
                $path === '/kelola/kliring/index' => Http::response($this->listPage($pending)),
                $path === '/kelola/kliring/riwayat-index' => Http::response($this->listPage($history, 'riwayat-view')),
                $path === '/kelola/kliring/view' && $r->method() === 'POST' && isset($r->data()['btn-simpan']) => Http::response(json_encode(['status' => 'success'])),
                $path === '/kelola/kliring/view' && $r->method() === 'POST' && isset($r->data()['btn-terima']) => Http::response((string) ($accepted = $acceptWorks)),
                $path === '/kelola/kliring/view' => Http::response($this->viewPage($accepted ? 'Bebas' : 'Belum Bebas')),
                default => Http::response('<html>home</html>'),
            };
        });
    }

    public function test_approving_a_submission_accepts_the_kliring_request(): void
    {
        $this->connectSession();
        $this->fakeSite([['201101234567', 'Belum Bebas', 'OTHER'], [self::NIM, 'Belum Bebas', 'PARAM1']]);
        $submission = $this->stagedSubmission(['status' => Submission::AI_ACCEPTED]);

        $this->actingAs($admin = $this->admin());
        app(SubmissionService::class)->approve($submission, $admin, null);

        $submission->refresh();
        $this->assertSame('done', $submission->kliring_status);
        $this->assertNotNull($submission->kliring_at);
        // The LIKE search also returned a longer NIM; only the exact one is touched.
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'param=OTHER'));
        Http::assertSent(function (Request $r) {
            if ($r->method() !== 'POST' || ! isset($r->data()['btn-simpan'])) {
                return false;
            }
            parse_str($r->data()['data'], $form);

            return str_contains($r->url(), 'param=PARAM1&itemid=2P') && $form['KliringSyarat']['klirIsValid'] === 'Ya'
                && $r->header('X-CSRF-Token')[0] === 'csrf-xyz';
        });
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && ($r->data()['btn-terima'] ?? null) == 1
            && str_ends_with($r->url(), 'param=PARAM1'));
        $this->assertDatabaseHas('activity_logs', ['action' => 'kliring.approved', 'submission_id' => $submission->id]);
    }

    public function test_request_already_free_in_history_is_marked_done_without_changes(): void
    {
        $this->connectSession();
        $this->fakeSite([], [[self::NIM, 'Bebas', 'PARAM9']]);
        $submission = $this->stagedSubmission(['status' => Submission::APPROVED]);

        (new ApproveInKliring($submission->id))->handle(app(KliringClient::class));

        $this->assertSame('done', $submission->fresh()->kliring_status);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    }

    public function test_missing_request_waits_and_expires_after_thirty_days(): void
    {
        $this->connectSession();
        $this->fakeSite([]);
        $submission = $this->stagedSubmission(['status' => Submission::APPROVED, 'verified_at' => now()]);

        (new ApproveInKliring($submission->id))->handle(app(KliringClient::class));
        $this->assertSame('waiting_request', $submission->fresh()->kliring_status);

        Queue::fake();
        $this->assertSame(1, ApproveInKliring::dispatchWaiting());
        Queue::assertPushed(ApproveInKliring::class);

        $submission->update(['verified_at' => now()->subDays(31)]);
        $this->assertSame(0, ApproveInKliring::dispatchWaiting());
        $this->assertSame('failed', $submission->fresh()->kliring_status);
    }

    public function test_expired_session_waits_for_reconnect(): void
    {
        $this->connectSession();
        Http::fake(['*' => Http::response('', 302, ['Location' => 'https://kliring.test/login'])]);
        $submission = $this->stagedSubmission(['status' => Submission::APPROVED]);

        (new ApproveInKliring($submission->id))->handle(app(KliringClient::class));

        $this->assertSame('waiting_login', $submission->fresh()->kliring_status);
        $this->assertFalse(app(KliringClient::class)->hasSession());
    }

    public function test_accept_that_does_not_stick_fails_loudly(): void
    {
        $this->connectSession();
        $this->fakeSite([[self::NIM, 'Belum Bebas', 'PARAM1']], [], acceptWorks: false);

        $this->expectExceptionMessage('status kliring masih "Belum Bebas"');
        app(KliringClient::class)->approve('PARAM1');
    }

    public function test_admin_connects_with_captcha(): void
    {
        $loginAttempts = 0;
        Http::fake(function (Request $r) use (&$loginAttempts) {
            $path = parse_url($r->url(), PHP_URL_PATH);
            if ($path === '/captcha') {
                return Http::response('PNG', 200, ['Content-Type' => 'image/png']);
            }
            if ($path === '/login' && $r->method() === 'POST') {
                return ++$loginAttempts === 1
                    ? Http::response('<div class="help-block help-block-error">Kode verifikasi salah.</div><input name="LoginForm[password]">')
                    : Http::response('', 302, ['Location' => 'https://kliring.test/']);
            }

            return Http::response('<form><input type="hidden" name="_csrf" value="tok"><img src="/captcha?v=1"></form>', 200,
                ['Set-Cookie' => 'PHPSESSID=new1; path=/; HttpOnly']);
        });

        $component = Livewire::actingAs($this->admin())->test(Kliring::class)
            ->assertSet('connected', false)
            ->call('connect')->assertSet('captcha', 'data:image/png;base64,'.base64_encode('PNG'));

        $component->set('code', 'salah')->call('login')->assertSet('connected', false)
            ->assertSee('Kode verifikasi salah.');
        $component->set('code', 'benar')->call('login')->assertSet('connected', true)->assertSet('captcha', null);

        $this->assertTrue(app(KliringClient::class)->hasSession());
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && ($r->data()['LoginForm']['verifyCode'] ?? $r->data()['LoginForm[verifyCode]'] ?? null) === 'benar'
            && ($r->data()['_csrf'] ?? null) === 'tok');
    }

    public function test_approve_without_kliring_account_skips_it(): void
    {
        Queue::fake();
        config(['services.kliring.username' => null]);
        $submission = $this->stagedSubmission(['status' => Submission::AI_ACCEPTED]);

        $this->actingAs($admin = $this->admin());
        app(SubmissionService::class)->approve($submission, $admin, null);

        $this->assertNull($submission->fresh()->kliring_status);
        Queue::assertNotPushed(ApproveInKliring::class);
    }
}
