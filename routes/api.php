<?php

use App\Http\Controllers\Api\Admin\CrawledPageController;
use App\Http\Controllers\Api\Admin\LegalDocumentController;
use App\Http\Controllers\Api\Admin\LegalSourceController;
use App\Http\Controllers\Api\Admin\SystemPromptController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CaseProgressController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\DemoRequestController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\FeedbackController;
use App\Http\Controllers\Api\GeneratedDocumentController;
use App\Http\Controllers\Api\KycController;
use App\Http\Controllers\Api\LabelController;
use App\Http\Controllers\Api\LegalCaseController;
use App\Http\Controllers\Api\LegalPageController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\TemplateController;
use App\Http\Controllers\Api\TermsController;
use App\Http\Controllers\Api\TodoController;
use App\Http\Controllers\Api\TourController;
use App\Http\Controllers\Api\TrialCodeController;
use Illuminate\Support\Facades\Route;

Route::post('/subscriptions/webhook', [SubscriptionController::class, 'webhook']);
Route::post('/subscriptions/webhook/lemonsqueezy', [SubscriptionController::class, 'lemonsqueezyWebhook']);

Route::get('/plans', [PlanController::class, 'index']);

Route::post('/demo-requests', [DemoRequestController::class, 'store'])
    ->middleware('throttle:demo-request');

// Looked up by the login screen (pre-auth) to greet a returning user with
// their last-used time. Throttled because it reveals whether an email ever
// used the app, which an attacker could otherwise enumerate.
Route::get('/auth/last-used', [AuthController::class, 'lastUsed'])
    ->middleware('throttle:last-used-lookup');

// The published Terms of Service / Privacy Policy is public content; the
// /legal/terms page links it from the register form, which logged-out
// visitors can open. Acceptance and status are per-user and stay protected.
Route::get('/terms/document', [TermsController::class, 'document']);

Route::middleware(['auth:supabase', 'track_last_used'])->group(function (): void {
    Route::get('/user', [AuthController::class, 'user'])->name('user');

    Route::get('/terms/status', [TermsController::class, 'status']);
    Route::post('/terms/accept', [TermsController::class, 'accept']);

    Route::get('/kyc', [KycController::class, 'show']);
    Route::put('/kyc', [KycController::class, 'store']);
    Route::delete('/kyc', [KycController::class, 'destroy']);

    Route::post('/tour/complete', [TourController::class, 'complete']);

    Route::get('/legal-pages/resolve', [LegalPageController::class, 'resolve']);
    Route::get('/legal-pages/{crawledPage}', [LegalPageController::class, 'show']);

    // The in-app notification feed lives outside the active_subscription group
    // so the navbar bell works for trial and suspended users too.
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::patch('/notifications/{notification}', [NotificationController::class, 'markRead']);
    Route::delete('/notifications/{notification}', [NotificationController::class, 'destroy']);

    // Trial redemption sits outside the active_subscription group: the whole
    // point is to be reachable by an account that has no subscription yet.
    Route::get('/trial/code', [TrialCodeController::class, 'show']);
    Route::post('/trial/redeem', [TrialCodeController::class, 'store'])
        ->middleware('throttle:6,1');

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
        Route::apiResource('labels', LabelController::class)->only(['index', 'store', 'update', 'destroy']);

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
        Route::get('/cases/{case}/progress', [CaseProgressController::class, 'show']);
        Route::patch('/cases/{case}/status', [LegalCaseController::class, 'updateStatus']);
        Route::post('/cases/{case}/conversations', [LegalCaseController::class, 'storeConversation']);
        Route::post('/cases/{case}/duplicate', [LegalCaseController::class, 'duplicate']);
        Route::post('/cases/{case}/restore', [LegalCaseController::class, 'restore']);
        Route::delete('/cases/{case}/force', [LegalCaseController::class, 'forceDestroy']);
    });

    Route::prefix('admin')->middleware('is_admin')->group(function (): void {
        Route::apiResource('legal-sources', LegalSourceController::class)->only(['index', 'store', 'destroy']);
        Route::post('/legal-sources/{legalSource}/crawl-now', [LegalSourceController::class, 'crawlNow']);
        Route::get('/crawled-pages', [CrawledPageController::class, 'index']);
        Route::get('/legal-documents', [LegalDocumentController::class, 'index']);
        Route::post('/legal-documents', [LegalDocumentController::class, 'store']);
        Route::get('/legal-documents/{crawledPage}/file', [LegalDocumentController::class, 'file']);
        Route::delete('/legal-documents/{crawledPage}', [LegalDocumentController::class, 'destroy']);
        Route::get('/system-prompts', [SystemPromptController::class, 'index']);
        Route::post('/system-prompts', [SystemPromptController::class, 'store']);
        Route::post('/system-prompts/{systemPrompt}/activate', [SystemPromptController::class, 'activate']);
    });
});
