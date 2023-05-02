<?php

use App\Http\Controllers\Databackd\Reports\CashflowReportController;
use Illuminate\Support\Facades\Route;

// Loaded by RouteServiceProvider::mapDatabackdRoutes() with the "account" prefix and the web/auth middleware.
Route::prefix('databackd/reports/cashflow')->middleware(\App\Http\Middleware\Databackd\RequireModule::class . ':databackd_reports')->name('databackd.reports.cashflow.')->group(function () {
    Route::get('excel', [CashflowReportController::class, 'excel'])->name('excel');
    Route::get('pdf', [CashflowReportController::class, 'pdf'])->name('pdf');
    Route::get('/', [CashflowReportController::class, 'index'])->name('index');
});
