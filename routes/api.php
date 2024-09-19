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
Route::get('/mikrotik/get-Hotspot-User', [MikrotikController::class, 'getHotspotUsers']);
Route::get('/mikrotik/get-Hotspot-users1', [MikrotikController::class, 'getHotspotUsers1']);
Route::put('/login', [AuthController::class, 'login']);
Route::delete('/mikrotik/deleteExpiredHotspotUsers', [MikrotikController::class, 'deleteExpiredHotspotUsers']);
Route::put('/mikrotik/extend-Hotspot', [MikrotikController::class, 'extendHotspotUserTime']);
Route::put('/mikrotik/login-hotspot-user', [MikrotikController::class, 'loginHotspotUser']);

Route::get('/mikrotik/get-Hotspot-by-phone/{no_hp}', [MikrotikController::class, 'getHotspotUserByPhoneNumber']);

Route::post('/mikrotik/add', [MikrotikController::class, 'addMenu']);
Route::put('/mikrotik/edit/{id}', [MikrotikController::class, 'editMenu']);
Route::post('/mikrotik/add-Hotspot-Limitasi', [MikrotikController::class, 'addHotspotUser1']);

Route::get('/mikrotik/get-all-menu', [MikrotikController::class, 'getAllMenus']);
Route::get('/mikrotik/get-all-order', [MikrotikController::class, 'getAllOrders']);
Route::get('/mikrotik/get-exist-user', [MikrotikController::class, 'checkUserExists']);
Route::match(['post', 'put'], '/mikrotik/add-Hotspot-User', [MikrotikController::class, 'addHotspotUser']);

Route::get('/mikrotik/get-profile', [MikrotikController::class, 'getHotspotProfile']);
Route::post('/mikrotik/set-profile', [MikrotikController::class, 'setHotspotProfile']);
Route::delete('/mikrotik/delete-profile', [MikrotikController::class, 'deleteHotspotProfile']);
