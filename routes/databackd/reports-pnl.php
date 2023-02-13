<?php

use App\Http\Controllers\Databackd\Reports\PnlReportController;
use Illuminate\Support\Facades\Route;

// Loaded by RouteServiceProvider::mapDatabackdRoutes() with the "account" prefix and the web/auth middleware.
Route::prefix('databackd/reports/pnl')->middleware(\App\Http\Middleware\Databackd\RequireModule::class . ':databackd_reports')->name('databackd.reports.pnl.')->group(function () {
    Route::get('excel', [PnlReportController::class, 'excel'])->name('excel');
    Route::get('pdf', [PnlReportController::class, 'pdf'])->name('pdf');
    Route::get('/', [PnlReportController::class, 'index'])->name('index');
});
