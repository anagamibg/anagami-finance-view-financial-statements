<?php

use App\Http\Controllers\Databackd\Analytics\ConsolidationController;
use Illuminate\Support\Facades\Route;

// Loaded by RouteServiceProvider::mapDatabackdRoutes() with the "account" prefix and the web/auth middleware.
Route::prefix('databackd/analytics/consolidation')->middleware(\App\Http\Middleware\Databackd\RequireModule::class . ':databackd_analytics')->name('databackd.analytics.consolidation.')->group(function () {
    Route::get('export', [ConsolidationController::class, 'export'])->name('export');
    Route::get('eliminations', [ConsolidationController::class, 'eliminations'])->name('eliminations');
    Route::get('/', [ConsolidationController::class, 'index'])->name('index');
});
