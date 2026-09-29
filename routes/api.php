<?php

use App\Http\Controllers\Api\PublicSubmissionController;
use Illuminate\Support\Facades\Route;

Route::get('form', [PublicSubmissionController::class, 'form'])->middleware('throttle:status');
Route::get('nim/{nim}', [PublicSubmissionController::class, 'checkNim'])->middleware('throttle:nim-check');
Route::post('submissions', [PublicSubmissionController::class, 'store'])->middleware('throttle:upload');
Route::get('status/{token}', [PublicSubmissionController::class, 'status'])
    ->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:status');
