<?php

use App\Http\Controllers\AiController;
use App\Http\Controllers\FormController;
use App\Http\Controllers\ImportController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::post('/forms', [FormController::class, 'store'])->name('forms.store');
Route::get('/forms/create', fn() => view('forms.create'))->name('forms.create');
Route::get('/forms/{form}', [FormController::class, 'show'])->name('forms.show');
Route::get('/forms/{publicUuid}/fill', [FormController::class, 'fill'])->name('forms.fill');
Route::post('/forms/{publicUuid}/submit', [FormController::class, 'submit'])->name('forms.submit');
Route::get('/forms/{form}/export', [FormController::class, 'export'])->name('forms.export');

Route::post('/ai/generate', [AiController::class, 'generate'])->name('ai.generate');

Route::post('/imports', [ImportController::class, 'store'])->name('imports.store');
