<?php

use App\Http\Controllers\Databackd\Reports\CounterpartyReportController;
use Illuminate\Support\Facades\Route;

// Loaded by RouteServiceProvider::mapDatabackdRoutes() with the "account" prefix and the web/auth middleware.
// The counterparty of the detail goes in ?name= (names contain "/").
Route::prefix('databackd/reports/counterparties')->middleware(\App\Http\Middleware\Databackd\RequireModule::class . ':databackd_reports')->name('databackd.reports.counterparties.')->group(function () {
    Route::get('excel', [CounterpartyReportController::class, 'excel'])->name('excel');
    Route::get('pdf', [CounterpartyReportController::class, 'pdf'])->name('pdf');
    Route::get('show', [CounterpartyReportController::class, 'show'])->name('show');
    Route::get('/', [CounterpartyReportController::class, 'index'])->name('index');
});
