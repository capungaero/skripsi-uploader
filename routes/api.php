<?php

use App\Http\Controllers\Api\PublicSubmissionController;
use App\Http\Controllers\Api\StudentAuthController;
use App\Http\Controllers\Api\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('form', [PublicSubmissionController::class, 'form'])->middleware('throttle:status');
Route::get('nim/{nim}', [PublicSubmissionController::class, 'checkNim'])->middleware('throttle:nim-check');
Route::post('submissions', [PublicSubmissionController::class, 'store'])->middleware(['student', 'throttle:upload']);
Route::post('login', [StudentAuthController::class, 'login'])->middleware('throttle:student-login');
Route::get('me', [StudentAuthController::class, 'me'])->middleware('student:always');
Route::post('logout', [StudentAuthController::class, 'logout'])->middleware('student:always');
Route::post('wa/webhook', WhatsAppWebhookController::class)->middleware('throttle:600,1');
Route::get('status/{token}', [PublicSubmissionController::class, 'status'])
    ->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:status');
