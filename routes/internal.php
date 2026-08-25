<?php

use App\Http\Controllers\InternalAiController;
use Illuminate\Support\Facades\Route;

Route::middleware('ai.internal')->group(function (): void {
    Route::get('/conversations/{conversation}/context', [InternalAiController::class, 'context']);
    Route::post('/conversations/{conversation}/messages', [InternalAiController::class, 'messages']);
    Route::post('/conversations/{conversation}/todos', [InternalAiController::class, 'todos']);
    Route::post('/conversations/{conversation}/advisories', [InternalAiController::class, 'advisories']);
    Route::post('/conversations/{conversation}/letters', [InternalAiController::class, 'letters']);
    Route::post('/conversations/{conversation}/memory', [InternalAiController::class, 'memory']);
});
