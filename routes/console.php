<?php

use App\Models\Submission;
use App\Models\User;
use App\Services\Staging;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

Artisan::command('app:create-admin {--role=superadmin}', function () {
    $email = text('Email admin', required: true, validate: fn ($v) => filter_var($v, FILTER_VALIDATE_EMAIL) ? null : 'Email tidak valid');
    $name = text('Nama', required: true);
    $pass = password('Password (min. 12 karakter)', required: true,
        validate: fn ($v) => mb_strlen($v) >= 12 ? null : 'Minimal 12 karakter');

    User::updateOrCreate(['email' => strtolower($email)], [
        'name' => $name,
        'password' => $pass,
        'role' => $this->option('role') === 'verifikator' ? 'verifikator' : 'superadmin',
        'is_active' => true,
    ]);
    $this->info("Admin {$email} siap. Login di /admin/login");
})->purpose('Create or reset an admin account');

Artisan::command('app:cleanup-staging', function () {
    $cutoff = now()->subHours(config('skripsi.staging_ttl_hours'))->getTimestamp();
    $removed = 0;

    foreach (glob(Staging::dir().DIRECTORY_SEPARATOR.'*.pdf') as $file) {
        if (filemtime($file) > $cutoff) {
            continue;
        }
        $submission = Submission::where('staging_path', basename($file))->first();
        // Keep files still waiting for the AI check or for a cloud provider to be connected.
        $stillNeeded = $submission && ! $submission->cloud_file_id
            && in_array($submission->status, [Submission::CHECKING, Submission::AI_ERROR, Submission::AI_ACCEPTED, Submission::APPROVED], true);
        if (! $stillNeeded) {
            @unlink($file);
            $submission?->forceFill(['staging_path' => null])->save();
            $removed++;
        }
    }
    $this->info("Removed {$removed} staging file(s).");
})->purpose('Delete staging PDFs that are no longer needed');

Schedule::command('app:cleanup-staging')->hourly();
