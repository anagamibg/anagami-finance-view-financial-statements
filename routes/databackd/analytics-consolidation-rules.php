<?php

use App\Http\Controllers\Databackd\Analytics\ConsolidationGroupController;
use App\Http\Controllers\Databackd\Analytics\ConsolidationRuleController;
use Illuminate\Support\Facades\Route;

// AFV Analytics / P6: consolidation groups, member legal entities and elimination rules.
// Loaded with prefix "account" and the standard auth middleware.

Route::prefix('databackd/analytics/consolidation-rules')->middleware(\App\Http\Middleware\Databackd\RequireModule::class . ':databackd_analytics')->name('databackd.analytics.consolidation-rules.')->group(function () {
    Route::get('/', [ConsolidationGroupController::class, 'index'])->name('index');
    Route::get('create', [ConsolidationGroupController::class, 'create'])->name('create');
    Route::post('/', [ConsolidationGroupController::class, 'store'])->name('store');
    Route::get('{group}', [ConsolidationGroupController::class, 'show'])->whereNumber('group')->name('show');
    Route::get('{group}/edit', [ConsolidationGroupController::class, 'edit'])->whereNumber('group')->name('edit');
    Route::put('{group}', [ConsolidationGroupController::class, 'update'])->whereNumber('group')->name('update');
    Route::delete('{group}', [ConsolidationGroupController::class, 'destroy'])->whereNumber('group')->name('destroy');

    Route::put('{group}/entities', [ConsolidationGroupController::class, 'updateEntities'])->whereNumber('group')->name('entities.update');
    Route::post('{group}/quick-intercompany', [ConsolidationGroupController::class, 'quickIntercompany'])->whereNumber('group')->name('quick-intercompany');

    Route::get('{group}/rules/create', [ConsolidationRuleController::class, 'create'])->whereNumber('group')->name('rules.create');
    Route::post('{group}/rules', [ConsolidationRuleController::class, 'store'])->whereNumber('group')->name('rules.store');
    Route::post('{group}/rules/sort', [ConsolidationRuleController::class, 'sort'])->whereNumber('group')->name('rules.sort');
    Route::get('{group}/rules/{rule}/edit', [ConsolidationRuleController::class, 'edit'])->whereNumber(['group', 'rule'])->name('rules.edit');
    Route::put('{group}/rules/{rule}', [ConsolidationRuleController::class, 'update'])->whereNumber(['group', 'rule'])->name('rules.update');
    Route::delete('{group}/rules/{rule}', [ConsolidationRuleController::class, 'destroy'])->whereNumber(['group', 'rule'])->name('rules.destroy');
});
