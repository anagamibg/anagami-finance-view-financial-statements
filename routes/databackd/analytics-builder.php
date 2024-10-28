<?php

use App\Http\Controllers\Databackd\Analytics\RatioBuilderController;
use Illuminate\Support\Facades\Route;

Route::prefix('databackd/analytics/builder')->middleware(\App\Http\Middleware\Databackd\RequireModule::class . ':databackd_analytics')->name('databackd.analytics.builder.')->group(function () {
    Route::get('/', [RatioBuilderController::class, 'index'])->name('index');
    Route::get('create', [RatioBuilderController::class, 'create'])->name('create');
    Route::post('/', [RatioBuilderController::class, 'store'])->name('store');
    // Live preview of a formula (AJAX). GET is accepted too so the endpoint can be checked without a CSRF token.
    Route::match(['get', 'post'], 'preview', [RatioBuilderController::class, 'preview'])->name('preview');
    Route::get('{ratio}/edit', [RatioBuilderController::class, 'edit'])->whereNumber('ratio')->name('edit');
    Route::put('{ratio}', [RatioBuilderController::class, 'update'])->whereNumber('ratio')->name('update');
    Route::post('{ratio}/toggle', [RatioBuilderController::class, 'toggle'])->whereNumber('ratio')->name('toggle');
    Route::get('{ratio}/targets', [RatioBuilderController::class, 'targets'])->whereNumber('ratio')->name('targets');
    Route::put('{ratio}/targets', [RatioBuilderController::class, 'updateTargets'])->whereNumber('ratio')->name('targets.update');
    Route::delete('{ratio}', [RatioBuilderController::class, 'destroy'])->whereNumber('ratio')->name('destroy');
});
