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
Route::post('/mikrotik/add-user', [MikrotikController::class, 'addUser']);
Route::post('/mikrotik/add-Hotspot-User', [MikrotikController::class, 'addHotspotUser']);
Route::get('/mikrotik/get-users', [MikrotikController::class, 'getUsers']);
Route::get('/mikrotik/get-Hotspot-users', [MikrotikController::class, 'getHotspotUsers']);
Route::delete('/mikrotik/delete/{id}', [MikrotikController::class, 'deleteUser'])
    ->where('id', '[\*a-zA-Z0-9]+');
Route::post('/login', [AuthController::class, 'login']);
Route::delete('/mikrotik/deleteExpiredUsers', [MikrotikController::class, 'deleteExpiredUsers']);
Route::delete('/mikrotik/deleteExpiredHotspotUsers', [MikrotikController::class, 'deleteExpiredHotspotUsers']);
Route::put('/mikrotik/extend-time', [MikrotikController::class, 'extendUserTime']);
Route::put('/mikrotik/extend-time', [MikrotikController::class, 'extendHotspotUserTime']);

