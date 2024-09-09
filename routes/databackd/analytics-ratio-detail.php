<?php

use App\Http\Controllers\Databackd\Analytics\RatioComparisonController;
use App\Http\Controllers\Databackd\Analytics\RatioDetailController;
use Illuminate\Support\Facades\Route;

// P3: ratio detail, legal entity comparison and the Excel export of ratios (URLs: /account/databackd/analytics/...)
Route::prefix('databackd/analytics')->middleware(\App\Http\Middleware\Databackd\RequireModule::class . ':databackd_analytics')->group(function () {
    // GET ?mode=dashboard|comparison&period_type=month|quarter|year&period=2026-10|2026-Q3|2026&from=&to=&entity=all|<id>
    Route::get('ratio/export', [RatioDetailController::class, 'export'])->name('databackd.analytics.ratio-detail.export');
    Route::get('ratio/{ratio}', [RatioDetailController::class, 'show'])->whereNumber('ratio')->name('databackd.analytics.ratio-detail.show');
    Route::get('comparison', [RatioComparisonController::class, 'index'])->name('databackd.analytics.comparison.index');
});
