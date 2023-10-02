<?php

use App\Http\Controllers\Databackd\Gaps\Statutory\StatutoryController;
use Illuminate\Support\Facades\Route;

// Loaded by RouteServiceProvider::mapDatabackdRoutes() with the "account" prefix and the web/auth middleware.
// B3 - annual financial statements by NSS (simplified): balance sheet and income statement.
Route::prefix('databackd/gaps/statutory')->middleware(\App\Http\Middleware\Databackd\RequireModule::class . ':databackd_reports')->name('databackd.gaps.statutory.')->group(function () {
    Route::get('excel', [StatutoryController::class, 'excel'])->name('excel');
    Route::get('pdf', [StatutoryController::class, 'pdf'])->name('pdf');
    Route::get('print', [StatutoryController::class, 'print'])->name('print');
    Route::get('/', [StatutoryController::class, 'index'])->name('index');
});
