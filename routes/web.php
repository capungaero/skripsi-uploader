<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\CloudOAuthController;
use App\Http\Controllers\Admin\ExportController;
use App\Http\Controllers\Admin\SubmissionFileController;
use App\Http\Controllers\ClientPageController;
use App\Http\Middleware\EnsureActiveAdmin;
use App\Livewire\Admin;
use Illuminate\Support\Facades\Route;

Route::get('/', ClientPageController::class)->name('client');

Route::prefix('admin')->name('admin.')->group(function () {
    Route::middleware('guest')->group(function () {
        Route::get('login', [AuthController::class, 'show'])->name('login');
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:admin-login');
    });

    Route::middleware(['auth', EnsureActiveAdmin::class])->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');

        Route::livewire('/', Admin\Dashboard::class)->name('dashboard');
        Route::livewire('submissions', Admin\SubmissionIndex::class)->name('submissions');
        Route::livewire('submissions/{submission}', Admin\SubmissionShow::class)->name('submissions.show');
        Route::get('submissions/{submission}/file', SubmissionFileController::class)->name('submissions.file');
        Route::get('export', ExportController::class)->name('export');

        Route::middleware('can:superadmin')->group(function () {
            Route::livewire('settings/appearance', Admin\Settings\Appearance::class)->name('settings.appearance');
            Route::livewire('settings/form', Admin\Settings\FormFields::class)->name('settings.form');
            Route::livewire('settings/faculties', Admin\Settings\Faculties::class)->name('settings.faculties');
            Route::livewire('settings/ai', Admin\Settings\AiConfig::class)->name('settings.ai');
            Route::livewire('settings/cloud', Admin\Settings\CloudStorage::class)->name('settings.cloud');
            Route::livewire('settings/whatsapp', Admin\Settings\WhatsApp::class)->name('settings.whatsapp');
            Route::livewire('settings/dspace', Admin\Settings\Dspace::class)->name('settings.dspace');
            Route::livewire('users', Admin\Users::class)->name('users');
            Route::livewire('logs', Admin\ActivityLogs::class)->name('logs');

            Route::get('oauth/{provider}/connect', [CloudOAuthController::class, 'redirect'])->name('oauth.connect');
            Route::get('oauth/{provider}/callback', [CloudOAuthController::class, 'callback'])->name('oauth.callback');
        });
    });
});
