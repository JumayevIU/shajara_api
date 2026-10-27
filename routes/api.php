<?php

use App\Http\Controllers\PersonController;
use App\Http\Controllers\TelegramWebhookController;
use App\Http\Controllers\TreeController;
use App\Http\Middleware\TelegramAuth;
use Illuminate\Support\Facades\Route;

// Bot webhook — Telegram'ning o'zi chaqiradi (secret token bilan himoyalangan)
Route::post('/telegram/webhook', [TelegramWebhookController::class, 'handle']);

Route::middleware([TelegramAuth::class])->group(function () {
    Route::get('/state', [TreeController::class, 'state']);
    Route::post('/tree', [TreeController::class, 'store']);
    Route::post('/tree/join', [TreeController::class, 'join']);
    Route::delete('/tree/members/{id}', [TreeController::class, 'removeMember']);
    Route::post('/tree/leave', [TreeController::class, 'leave']);
    Route::post('/tree/rotate-code', [TreeController::class, 'rotateCode']);
    Route::get('/invites/{code}', [TreeController::class, 'invitePreview']);

    Route::post('/persons', [PersonController::class, 'store']);
    Route::post('/persons/{id}/link', [PersonController::class, 'link']);
    Route::patch('/persons/{id}', [PersonController::class, 'update']);
    Route::delete('/persons/{id}', [PersonController::class, 'destroy']);
    Route::post('/persons/{id}/claim', [PersonController::class, 'claim']);
});
