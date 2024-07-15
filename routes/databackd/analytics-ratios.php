<?php

use App\Http\Controllers\Databackd\Analytics\RatioDashboardController;
use Illuminate\Support\Facades\Route;

Route::prefix('databackd/analytics/ratios')->middleware(\App\Http\Middleware\Databackd\RequireModule::class . ':databackd_analytics')->name('databackd.analytics.ratios.')->group(function () {
    Route::get('/', [RatioDashboardController::class, 'index'])->name('index');
});
