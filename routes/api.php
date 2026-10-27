<?php

use App\Http\Controllers\PersonController;
use App\Http\Controllers\TreeController;
use App\Http\Middleware\TelegramAuth;
use Illuminate\Support\Facades\Route;

Route::middleware([TelegramAuth::class])->group(function () {
    Route::get('/state', [TreeController::class, 'state']);
    Route::post('/tree', [TreeController::class, 'store']);
    Route::post('/tree/join', [TreeController::class, 'join']);
    Route::get('/invites/{code}', [TreeController::class, 'invitePreview']);

    Route::post('/persons', [PersonController::class, 'store']);
    Route::post('/persons/{id}/link', [PersonController::class, 'link']);
    Route::patch('/persons/{id}', [PersonController::class, 'update']);
    Route::delete('/persons/{id}', [PersonController::class, 'destroy']);
    Route::post('/persons/{id}/claim', [PersonController::class, 'claim']);
});
