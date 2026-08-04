<?php

use App\Http\Controllers\AiController;
use App\Http\Controllers\FormController;
use App\Http\Controllers\ImportController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/forms', [FormController::class, 'index'])->name('forms.index');
Route::post('/forms', [FormController::class, 'store'])->name('forms.store');
Route::get('/forms/create', [FormController::class, 'create'])->name('forms.create');
Route::get('/forms/{form}/edit', [FormController::class, 'edit'])->name('forms.edit');
Route::put('/forms/{form}', [FormController::class, 'update'])->name('forms.update');
Route::get('/forms/{form}', [FormController::class, 'show'])->name('forms.show');
Route::get('/forms/{publicUuid}/fill', [FormController::class, 'fill'])->name('forms.fill');
Route::post('/forms/{publicUuid}/submit', [FormController::class, 'submit'])->name('forms.submit');
Route::get('/forms/{form}/export', [FormController::class, 'export'])->name('forms.export');

Route::post('/ai/generate', [AiController::class, 'generate'])->name('ai.generate');
Route::get('/ai/logs/{log}', [AiController::class, 'status'])->name('ai.status');

Route::post('/imports', [ImportController::class, 'store'])->name('imports.store');
Route::get('/imports/{import}/status', [ImportController::class, 'statusPage'])->name('imports.status.page');
Route::get('/imports/{import}/status.json', [ImportController::class, 'status'])->name('imports.status');
Route::get('/imports/{import}/preview', [ImportController::class, 'preview'])->name('imports.preview');
Route::post('/imports/{import}/commit', [ImportController::class, 'commit'])->name('imports.commit');
