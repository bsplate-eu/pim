<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/update-connector', function () {
    $path = storage_path('app/pim-connector.php');
    return response()->file($path);
})->name('update-connector');

Route::get('/update-connector/presta', function () {
    return response()->file(storage_path('app/pim-connector-presta.php'));
})->name('update-connector.presta');

Route::get('/update-connector/litecart', function () {
    return response()->file(storage_path('app/pim-connector-litecart.php'));
})->name('update-connector.litecart');

Route::get('/update-connector/opencart', function () {
    return response()->file(storage_path('app/pim-connector-opencart.php'));
})->name('update-connector.opencart');

Route::get('/', function () {
    return redirect('/admin');
});
