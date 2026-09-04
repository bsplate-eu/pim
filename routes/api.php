<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
Route::any('/baselinker/{integration_id}', [\App\Http\Controllers\Api\BaselinkerController::class, 'api']);
Route::any('/selly/{integration_id}', [\App\Http\Controllers\Api\SellyController::class, 'download']);

// ARGO Bridge melduje sie sam z LAN-u klienta — token w naglowku X-Argo-Token.
Route::post('/argo-bridge/ping', [\App\Http\Controllers\Api\ArgoBridgeController::class, 'ping'])->name('api.argo-bridge.ping');
