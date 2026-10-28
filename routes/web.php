<?php

use Illuminate\Support\Facades\Route;

// Mini app sahifasi — front va back bitta loyihada
Route::get('/', fn () => response()->file(public_path('app.html')));

// Admin panel sahifasi (kirish X-Admin-Key bilan, sahifaning o'zi ochiq HTML)
Route::get('/admin', fn () => response()->file(public_path('admin.html')));

// PDF eksport: bir martalik yuklab olish havolasi (Telegram ichidan)
Route::get('/export/{token}', [\App\Http\Controllers\ExportController::class, 'show']);
