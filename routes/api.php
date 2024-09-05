<?php

use App\Http\Controllers\MikrotikController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/mikrotik/connect', [MikrotikController::class, 'connectToMikrotik']);
Route::post('/mikrotik/add-ip', [MikrotikController::class, 'addIpAddress']);
Route::get('/mikrotik/check-connection', [MikrotikController::class, 'checkConnection']);
Route::post('/mikrotik/add-user', [MikrotikController::class, 'addUser']);
Route::get('/mikrotik/get-users', [MikrotikController::class, 'getUsers']);
Route::delete('/mikrotik/delete/{id}', [MikrotikController::class, 'deleteUser'])
    ->where('id', '[\*a-zA-Z0-9]+');
// routes/api.php
Route::post('/login', [AuthController::class, 'login']);



