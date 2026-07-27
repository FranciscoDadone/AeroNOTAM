<?php

use App\Http\Controllers\Api\NotamController;
use App\Http\Controllers\Api\WhatsappTestController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

$notamRoutes = function () {
    Route::get('/notams', [NotamController::class, 'index']);
    Route::get('/notams/aerodromos', [NotamController::class, 'aerodromos']);

    // Runs the full bot pipeline — AI airport matching included — on arbitrary
    // input, so it stays off any publicly reachable environment.
    if (app()->environment('local')) {
        Route::get('/whatsapp/test', [WhatsappTestController::class, 'index']);
    }
};

Route::middleware('throttle:notams')->group(function () use ($notamRoutes) {
    Route::prefix('v1')->group($notamRoutes);

    // The unversioned paths predate /v1 and stay as aliases so existing
    // clients keep working. New integrations should use /api/v1.
    $notamRoutes();
});
