<?php

use Illuminate\Support\Facades\Route;

// Mini app sahifasi — front va back bitta loyihada
Route::get('/', fn () => response()->file(public_path('app.html')));

// PDF eksport: bir martalik yuklab olish havolasi (Telegram ichidan)
Route::get('/export/{token}', [\App\Http\Controllers\ExportController::class, 'show']);
