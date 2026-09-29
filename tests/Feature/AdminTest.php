<?php

namespace Tests\Feature;

use App\Jobs\FinalizeApproved;
use App\Jobs\RemoveFromCloud;
use App\Jobs\SendWhatsApp;
use App\Livewire\Admin\Settings\AiConfig;
use App\Livewire\Admin\SubmissionShow;
use App\Models\ActivityLog;
use App\Models\Setting;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function admin(string $role = 'superadmin'): User
    {
        return User::create(['name' => 'Pustakawan', 'email' => $role.'@unand.ac.id', 'password' => 'rahasia-sekali-123', 'role' => $role]);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/admin/settings/ai')->assertRedirect('/admin/login');
    }

    public function test_password_is_hashed_and_login_is_logged_and_throttled(): void
    {
        $admin = $this->admin();
        $this->assertNotSame('rahasia-sekali-123', $admin->getRawOriginal('password'));

        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'rahasia-sekali-123'])->assertRedirect('/admin');
        $this->assertDatabaseHas('activity_logs', ['action' => 'auth.login', 'actor_id' => $admin->id]);
        $this->post('/admin/logout');

        foreach (range(1, 5) as $i) {
            $this->post('/admin/login', ['email' => $admin->email, 'password' => 'salah']);
        }
        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'rahasia-sekali-123'])->assertStatus(429);
    }

    public function test_verifikator_cannot_open_settings(): void
    {
        $this->actingAs($this->admin('verifikator'));

        $this->get('/admin/submissions')->assertOk();
        $this->get('/admin/settings/ai')->assertForbidden();
        $this->get('/admin/logs')->assertForbidden();
    }

    public function test_dashboard_and_list_render_with_search(): void
    {
        $this->actingAs($this->admin());
        $this->stagedSubmission(['status' => Submission::AI_ACCEPTED]);

        $this->get('/admin')->assertOk()->assertSee('Antrean verifikasi');
        $this->get('/admin/submissions?search=siti')->assertOk()->assertSee('2011012345');
        $this->get('/admin/submissions?search=tidakada')->assertOk()->assertDontSee('2011012345');
    }

    public function test_approve_records_verifier_and_queues_finalize(): void
    {
        Queue::fake();
        $this->settings(['wa.enabled' => true]);
        $admin = $this->admin('verifikator');
        $submission = $this->stagedSubmission(['status' => Submission::AI_ACCEPTED]);

        Livewire::actingAs($admin)->test(SubmissionShow::class, ['submission' => $submission])
            ->set('note', 'Lengkap')->call('approve')->assertHasNoErrors();

        $submission->refresh();
        $this->assertSame(Submission::APPROVED, $submission->status);
        $this->assertSame($admin->id, $submission->verified_by);
        Queue::assertPushed(FinalizeApproved::class);
        Queue::assertPushed(SendWhatsApp::class, fn ($j) => $j->event === 'admin_approved');
        $this->assertDatabaseHas('activity_logs', ['action' => 'submission.approved', 'actor_id' => $admin->id]);
    }

    public function test_reject_requires_note_and_removes_cloud_copy(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $submission = $this->stagedSubmission(['status' => Submission::AI_ACCEPTED, 'cloud_provider' => 'gdrive', 'cloud_file_id' => 'f1']);

        $component = Livewire::actingAs($admin)->test(SubmissionShow::class, ['submission' => $submission]);
        $component->set('note', '')->call('reject')->assertHasErrors('note');
        $component->set('note', 'Halaman pengesahan belum ditandatangani.')->call('reject')->assertHasNoErrors();

        $submission->refresh();
        $this->assertSame(Submission::ADMIN_REJECTED, $submission->status);
        $this->assertSame(['Halaman pengesahan belum ditandatangani.'], $submission->rejection_reasons);
        Queue::assertPushed(RemoveFromCloud::class);
    }

    public function test_cannot_approve_from_wrong_status(): void
    {
        $submission = $this->stagedSubmission(['status' => Submission::AI_REJECTED]);

        Livewire::actingAs($this->admin())->test(SubmissionShow::class, ['submission' => $submission])
            ->call('approve')->assertHasErrors('status');

        $this->assertSame(Submission::AI_REJECTED, $submission->fresh()->status);
    }

    public function test_ai_token_is_encrypted_and_write_only(): void
    {
        Livewire::actingAs($this->admin())->test(AiConfig::class)
            ->set('form.ai__api_key', 'sk-very-secret')
            ->set('form.ai__base_url', 'https://ai.test/v1')
            ->call('save')->assertHasNoErrors()
            ->assertSet('form.ai__api_key', '');

        $raw = Setting::where('key', 'ai.api_key')->value('value');
        $this->assertStringNotContainsString('sk-very-secret', $raw);
        $this->assertSame('sk-very-secret', $this->settings()->get('ai.api_key'));

        // Saving again with an empty field keeps the stored token.
        Livewire::actingAs(User::first())->test(AiConfig::class)->call('save');
        $this->settings()->flush();
        $this->assertSame('sk-very-secret', $this->settings()->get('ai.api_key'));

        $log = ActivityLog::where('action', 'settings.updated')->first();
        $this->assertStringNotContainsString('sk-very-secret', json_encode($log->meta));
    }

    public function test_export_csv_contains_filtered_rows(): void
    {
        $this->actingAs($this->admin());
        $this->stagedSubmission(['status' => Submission::APPROVED, 'judul' => '=HYPERLINK("x")']);

        $response = $this->get('/admin/export?status=approved')->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringContainsString('2011012345', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
    }
}
