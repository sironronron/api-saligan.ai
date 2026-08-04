<?php

use App\Http\Controllers\Api\Admin\CrawledPageController;
use App\Http\Controllers\Api\Admin\LegalSourceController;
use App\Http\Controllers\Api\Admin\SystemPromptController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\GeneratedDocumentController;
use App\Http\Controllers\Api\TodoController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/forgot-password', [AuthController::class, 'sendPasswordResetLink']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::apiResource('documents', DocumentController::class);

    Route::get('/generated-documents', [GeneratedDocumentController::class, 'index']);

    Route::apiResource('conversations', ConversationController::class);
    Route::post('/conversations/{conversation}/messages', [ChatController::class, 'store']);

    Route::post('/messages/{message}/export/word', [ExportController::class, 'word']);
    Route::post('/messages/{message}/export/pdf', [ExportController::class, 'pdf']);

    Route::apiResource('todos', TodoController::class)->only(['index', 'store', 'update', 'destroy']);

    Route::prefix('admin')->middleware('is_admin')->group(function (): void {
        Route::apiResource('legal-sources', LegalSourceController::class)->only(['index', 'store', 'destroy']);
        Route::post('/legal-sources/{legalSource}/crawl-now', [LegalSourceController::class, 'crawlNow']);
        Route::get('/crawled-pages', [CrawledPageController::class, 'index']);
        Route::get('/system-prompts', [SystemPromptController::class, 'index']);
        Route::post('/system-prompts', [SystemPromptController::class, 'store']);
        Route::post('/system-prompts/{systemPrompt}/activate', [SystemPromptController::class, 'activate']);
    });
});
