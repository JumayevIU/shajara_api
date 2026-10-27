<?php

use Illuminate\Support\Facades\Route;

// Mini app sahifasi — front va back bitta loyihada
Route::get('/', fn () => response()->file(public_path('app.html')));
