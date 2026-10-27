<?php

use App\Http\Controllers\PhotoController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('welcome'));
Route::get('/users/{id?}', [UserController::class, 'show'])->name('users.show');
Route::post('/users', [UserController::class, 'store']);
Route::resource('photos', PhotoController::class);
Route::middleware('auth')->group(function () {
    Route::get('/dashboard', fn () => 'ok');
});
// Route::view('/old', 'old');   commented out: must not be counted