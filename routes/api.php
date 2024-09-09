<?php

use App\Http\Controllers\MikrotikController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/mikrotik/connect', [MikrotikController::class, 'connectToMikrotik']);
Route::get('/mikrotik/check-connection', [MikrotikController::class, 'checkConnection']);
Route::post('/mikrotik/add-Hotspot-User', [MikrotikController::class, 'addHotspotUser']);
Route::get('/mikrotik/get-Hotspot-users', [MikrotikController::class, 'getHotspotUsers']);
Route::post('/login', [AuthController::class, 'login']);
Route::delete('/mikrotik/deleteExpiredHotspotUsers', [MikrotikController::class, 'deleteExpiredHotspotUsers']);
Route::put('/mikrotik/extend-Hotspot', [MikrotikController::class, 'extendHotspotUserTime']);
Route::put('/mikrotik/login-hotspot-user', [MikrotikController::class, 'loginHotspotUser']);
