<?php

use Illuminate\Support\Facades\Route;
use Joelseneque\AiPages\Http\Controllers;

Route::name('ai-pages.')->prefix('ai-pages')->group(function () {
    Route::get('/', [Controllers\BuildController::class, 'index'])->name('index');

    Route::get('build', [Controllers\BuildController::class, 'create'])->name('build');
    Route::post('build', [Controllers\BuildController::class, 'store'])->name('build.store');
    Route::get('blueprint-sets/{collection}', [Controllers\BuildController::class, 'sets'])->name('build.sets');

    Route::get('jobs', [Controllers\JobController::class, 'index'])->name('jobs');
    Route::get('jobs/{job}', [Controllers\JobController::class, 'show'])->name('jobs.show');
    Route::get('jobs/{job}/status', [Controllers\JobController::class, 'status'])->name('jobs.status');

    Route::get('instructions', [Controllers\InstructionsController::class, 'index'])->name('instructions');
    Route::post('instructions/sweep', [Controllers\InstructionsController::class, 'sweep'])->name('instructions.sweep');
    Route::get('instructions/edit/{name}', [Controllers\InstructionsController::class, 'edit'])
        ->where('name', '.*')->name('instructions.edit');
    Route::patch('instructions/edit/{name}', [Controllers\InstructionsController::class, 'update'])
        ->where('name', '.*')->name('instructions.update');

    Route::get('tweak/{entry}', [Controllers\EditController::class, 'create'])->name('tweak');
    Route::post('tweak/{entry}', [Controllers\EditController::class, 'store'])->name('tweak.store');
    Route::post('tweak/{entry}/apply/{job}', [Controllers\EditController::class, 'apply'])->name('tweak.apply');
});
