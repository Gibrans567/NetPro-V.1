<?php
use App\Http\Controllers\MikrotikController;
use Illuminate\Support\Facades\Route;



Route::get('/', function () {
    return response()->json(['message' => 'Bismillah bib!']);
});



Route::get('/mikrotik-connect', [MikrotikController::class, 'connectToMikrotik']);
Route::get('/check-connection', [MikrotikController::class, 'checkConnection']);
