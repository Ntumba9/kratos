<?php

use Illuminate\Support\Facades\Route;

// KRATOS CORE serves no HTML; the edge proxy routes "/" to KRATOS COMMAND.
Route::get('/', fn () => response()->json([
    'service' => 'kratos-core',
    'api' => url('/api/v1'),
]));
