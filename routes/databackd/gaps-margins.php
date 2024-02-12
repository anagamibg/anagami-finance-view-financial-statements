<?php

use App\Http\Controllers\Databackd\Gaps\Margins\MarginReportController;
use Illuminate\Support\Facades\Route;

// Loaded by RouteServiceProvider::mapDatabackdRoutes() with the "account" prefix and the web/auth middleware.
// B2 (S8): margins by products and services.
Route::prefix('databackd/gaps/margins')->middleware(\App\Http\Middleware\Databackd\RequireModule::class . ':databackd_reports')->name('databackd.gaps.margins.')->group(function () {
    Route::get('excel', [MarginReportController::class, 'excel'])->name('excel');
    Route::get('pdf', [MarginReportController::class, 'pdf'])->name('pdf');
    Route::get('/', [MarginReportController::class, 'index'])->name('index');
});
