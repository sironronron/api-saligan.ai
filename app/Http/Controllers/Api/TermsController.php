<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LegalDocument;
use App\Models\UserAcceptance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TermsController extends Controller
{
    /**
     * Get the current terms acceptance status.
     */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentVersion = LegalDocument::currentVersion();

        $accepted = $user->terms_accepted_at !== null;
        $needsReacceptance = $accepted && $user->terms_version !== $currentVersion;

        return response()->json([
            'accepted' => $accepted && ! $needsReacceptance,
            'needs_reacceptance' => $needsReacceptance,
            'accepted_at' => $user->terms_accepted_at,
            'accepted_version' => $user->terms_version,
            'current_version' => $currentVersion,
        ]);
    }

    /**
     * Get the current terms document content.
     */
    public function document(): JsonResponse
    {
        $document = LegalDocument::current();

        if (! $document) {
            return response()->json([
                'message' => 'No terms document has been published yet. Please contact support.',
            ], 503);
        }

        return response()->json([
            'title' => $document->title,
            'content' => $document->content,
            'hash' => $document->hash,
            'version' => $document->version,
            'effective_at' => $document->effective_at,
        ]);
    }

    /**
     * Record the user's acceptance of the terms.
     */
    public function accept(Request $request): JsonResponse
    {
        $request->validate([
            'marketing_opt_in' => 'boolean',
        ]);

        $document = LegalDocument::current();

        if (! $document) {
            return response()->json([
                'message' => 'No terms document has been published yet. Please contact support.',
            ], 503);
        }

        $user = $request->user();

        // Record the acceptance against the exact version and text that was shown.
        UserAcceptance::create([
            'user_id' => $user->id,
            'document_type' => $document->type,
            'accepted_at' => now(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'document_version' => $document->version,
            'document_hash' => $document->hash,
            'marketing_opt_in' => $request->boolean('marketing_opt_in', false),
        ]);

        // Update user's quick lookup fields
        $user->update([
            'terms_accepted_at' => now(),
            'terms_version' => $document->version,
            'marketing_opt_in' => $request->boolean('marketing_opt_in', false),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Terms accepted successfully.',
            'version' => $document->version,
        ]);
    }
}
