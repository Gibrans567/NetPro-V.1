<?php
use App\Http\Controllers\MikrotikController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});



Route::get('/mikrotik-connect', [MikrotikController::class, 'connectToMikrotik']);
Route::get('/check-connection', [MikrotikController::class, 'checkConnection']);
