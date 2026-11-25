<?php

use App\Http\Controllers\MobileSyncController;
use App\Http\Controllers\MobileAppController;
use App\Http\Middleware\AuthenticateMobile;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

Route::prefix('api/mobile')->group(function () {
    Route::get('health', fn () => abort_if(config('mobile.enabled'), 404) ?: response()->json(['service' => 'kardex'])->header('Cache-Control', 'no-store'))->middleware('throttle:120,1');
    Route::post('handoff', [MobileAppController::class, 'exchange'])->middleware('throttle:10,1');
    Route::post('login', [MobileSyncController::class, 'login'])->middleware('throttle:6,1');
    Route::middleware([AuthenticateMobile::class, SubstituteBindings::class])->group(function () {
        Route::post('logout', [MobileSyncController::class, 'logout']);
        Route::get('catalog', [MobileSyncController::class, 'catalog']);
        Route::post('drafts', [MobileSyncController::class, 'store'])->middleware('throttle:60,1');
        Route::post('drafts/{intervention}/complete', [MobileSyncController::class, 'complete']);
        Route::post('drafts/{intervention}/photos', [MobileSyncController::class, 'photo'])->middleware('throttle:60,1');
    });
});
