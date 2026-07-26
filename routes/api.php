<?php

use App\Http\Controllers\Api\NotamController;
use App\Http\Controllers\Api\WhatsappTestController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/notams', [NotamController::class, 'index']);
Route::get('/notams/aerodromos', [NotamController::class, 'aerodromos']);
Route::get('/whatsapp/test', [WhatsappTestController::class, 'index']);
