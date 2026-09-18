<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\SlideController;
use App\Http\Controllers\SlackCommandController;
use App\Http\Controllers\SlackInteractionController;
use App\Http\Middleware\VerifySlackSignature;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// ── Slack ─────────────────────────────────────────────────────────────────────
// Un solo endpoint per entrambi gli slash command (/gps-domanda, /gps-valida):
// Slack manda il nome del comando nel campo "command" del payload.
Route::post('/slack/commands', [SlackCommandController::class, 'handle'])
    ->middleware(VerifySlackSignature::class)
    ->name('slack.commands');

// Submission della modale di /gps-valida (tipo documento + upload PDF).
Route::post('/slack/interactions', [SlackInteractionController::class, 'handle'])
    ->middleware(VerifySlackSignature::class)
    ->name('slack.interactions');

// ── Admin (upload/ingestione slide) ───────────────────────────────────────────
Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.post');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::middleware('admin')->group(function () {
        Route::get('/slides', [SlideController::class, 'index'])->name('slides.index');
        Route::post('/slides', [SlideController::class, 'store'])->name('slides.store');
        Route::post('/slides/{slide}/ingest', [SlideController::class, 'ingest'])->name('slides.ingest');
        Route::delete('/slides/{slide}', [SlideController::class, 'destroy'])->name('slides.destroy');
    });
});
