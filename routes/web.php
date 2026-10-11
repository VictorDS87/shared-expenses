<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\ExpenseController;
use App\Http\Controllers\Web\ImportController;
use App\Http\Controllers\Web\ReportController;
use App\Http\Controllers\Web\UnitController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::view('/login', 'auth.login')->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
});
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::get('/despesas', [ExpenseController::class, 'index'])->name('expenses.index');
    Route::get('/despesas/nova', [ExpenseController::class, 'create'])->name('expenses.create');
    Route::post('/despesas', [ExpenseController::class, 'store'])->name('expenses.store');
    Route::get('/despesas/{expense}', [ExpenseController::class, 'show'])->name('expenses.show');
    Route::post('/despesas/{expense}/reprocessar', [ExpenseController::class, 'retry'])->middleware('throttle:10,1')->name('expenses.retry');
    Route::get('/unidades', [UnitController::class, 'index'])->name('units.index');
    Route::post('/unidades', [UnitController::class, 'store'])->name('units.store');
    Route::put('/unidades/{unit}', [UnitController::class, 'update'])->name('units.update');
    Route::get('/importacoes', [ImportController::class, 'index'])->name('imports.index');
    Route::post('/importacoes', [ImportController::class, 'store'])->middleware('throttle:10,1')->name('imports.store');
    Route::post('/importacoes/{import}/retomar', [ImportController::class, 'resume'])->middleware('throttle:10,1')->name('imports.resume');
    Route::get('/importacoes/{import}', [ImportController::class, 'show'])->name('imports.show');
    Route::get('/relatorios', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/relatorios/exportar', [ReportController::class, 'export'])->name('reports.export');
});
