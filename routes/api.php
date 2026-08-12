<?php

use App\Http\Controllers\Api\Admin\CrawledPageController;
use App\Http\Controllers\Api\Admin\LegalSourceController;
use App\Http\Controllers\Api\Admin\SystemPromptController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\DemoRequestController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\FeedbackController;
use App\Http\Controllers\Api\GeneratedDocumentController;
use App\Http\Controllers\Api\KycController;
use App\Http\Controllers\Api\LegalCaseController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\TemplateController;
use App\Http\Controllers\Api\TermsController;
use App\Http\Controllers\Api\TodoController;
use Illuminate\Support\Facades\Route;

Route::post('/subscriptions/webhook', [SubscriptionController::class, 'webhook']);
Route::post('/subscriptions/webhook/lemonsqueezy', [SubscriptionController::class, 'lemonsqueezyWebhook']);

Route::get('/plans', [PlanController::class, 'index']);

Route::post('/demo-requests', [DemoRequestController::class, 'store'])
    ->middleware('throttle:demo-request');

// The published Terms of Service / Privacy Policy is public content; the
// /legal/terms page links it from the register form, which logged-out
// visitors can open. Acceptance and status are per-user and stay protected.
Route::get('/terms/document', [TermsController::class, 'document']);

Route::middleware('auth:supabase')->group(function (): void {
    Route::get('/user', [AuthController::class, 'user']);

    Route::get('/terms/status', [TermsController::class, 'status']);
    Route::post('/terms/accept', [TermsController::class, 'accept']);

    Route::get('/kyc', [KycController::class, 'show']);
    Route::put('/kyc', [KycController::class, 'store']);
    Route::delete('/kyc', [KycController::class, 'destroy']);

    Route::get('/subscription', [SubscriptionController::class, 'show']);
    Route::post('/subscription', [SubscriptionController::class, 'store']);
    Route::post('/subscription/change-plan', [SubscriptionController::class, 'changePlan']);
    Route::post('/subscription/cancel', [SubscriptionController::class, 'cancel']);
    Route::post('/subscription/seats', [SubscriptionController::class, 'addSeats']);
    Route::delete('/subscription/seats', [SubscriptionController::class, 'removeSeats']);

    Route::get('/organizations', [OrganizationController::class, 'show']);
    Route::post('/organizations', [OrganizationController::class, 'store']);
    Route::get('/organizations/members', [OrganizationController::class, 'members']);
    Route::delete('/organizations/members/{member}', [OrganizationController::class, 'removeMember']);
    Route::post('/organizations/members/{member}/suspend', [OrganizationController::class, 'suspendMember']);
    Route::post('/organizations/members/{member}/resume', [OrganizationController::class, 'resumeMember']);

    Route::get('/organizations/invitations', [OrganizationController::class, 'indexInvitations']);
    Route::post('/organizations/invitations', [OrganizationController::class, 'storeInvitation']);
    Route::post('/organizations/invitations/accept', [OrganizationController::class, 'acceptInvitationByToken']);
    Route::delete('/organizations/invitations/{invitation}', [OrganizationController::class, 'revokeInvitation']);
    Route::post('/invitations/{invitation}/accept', [OrganizationController::class, 'acceptInvitation']);

    Route::middleware(['active_subscription', 'terms.accepted'])->group(function (): void {
        Route::apiResource('documents', DocumentController::class);
        Route::post('/documents/{document}/attach', [DocumentController::class, 'attach']);
        Route::get('/documents/{document}/file', [DocumentController::class, 'file']);

        Route::get('/generated-documents', [GeneratedDocumentController::class, 'index']);

        Route::apiResource('conversations', ConversationController::class);
        Route::post('/conversations/{conversation}/messages', [ChatController::class, 'store']);

        Route::post('/messages/{message}/export/word', [ExportController::class, 'word']);
        Route::post('/messages/{message}/export/pdf', [ExportController::class, 'pdf']);
        Route::post('/messages/{message}/feedback', [FeedbackController::class, 'store']);
        Route::delete('/messages/{message}/feedback', [FeedbackController::class, 'destroy']);

        Route::apiResource('todos', TodoController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::post('/todos/reorder', [TodoController::class, 'reorder']);

        Route::get('/templates', [TemplateController::class, 'index']);
        Route::post('/templates', [TemplateController::class, 'store']);
        Route::post('/templates/{template}/fill', [TemplateController::class, 'fill']);
        Route::delete('/templates/{template}', [TemplateController::class, 'destroy']);

        Route::apiResource('cases', LegalCaseController::class);
        Route::post('/cases/{case}/conversations', [LegalCaseController::class, 'storeConversation']);
        Route::post('/cases/{case}/duplicate', [LegalCaseController::class, 'duplicate']);
        Route::post('/cases/{case}/restore', [LegalCaseController::class, 'restore']);
        Route::delete('/cases/{case}/force', [LegalCaseController::class, 'forceDestroy']);
    });

    Route::prefix('admin')->middleware('is_admin')->group(function (): void {
        Route::apiResource('legal-sources', LegalSourceController::class)->only(['index', 'store', 'destroy']);
        Route::post('/legal-sources/{legalSource}/crawl-now', [LegalSourceController::class, 'crawlNow']);
        Route::get('/crawled-pages', [CrawledPageController::class, 'index']);
        Route::get('/system-prompts', [SystemPromptController::class, 'index']);
        Route::post('/system-prompts', [SystemPromptController::class, 'store']);
        Route::post('/system-prompts/{systemPrompt}/activate', [SystemPromptController::class, 'activate']);
    });
});
