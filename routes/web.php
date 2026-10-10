<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\Web\UnitController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::view('/login', 'auth.login')->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
});
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

Route::middleware('auth')->group(function () {
    Route::redirect('/', '/unidades')->name('dashboard');
    Route::get('/unidades', [UnitController::class, 'index'])->name('units.index');
    Route::post('/unidades', [UnitController::class, 'store'])->name('units.store');
    Route::put('/unidades/{unit}', [UnitController::class, 'update'])->name('units.update');
});
