<?php

namespace App\Http\Controllers\Api;

use App\Enums\MessageRole;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Support\PlanLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A single aggregated read of everything the post-login Dashboard needs, so
 * the frontend can paint an at-a-glance home in one round trip instead of
 * fanning out to every list endpoint it links into.
 */
class DashboardController extends Controller
{
    /**
     * Aggregate the current user's cases, tasks, drafts, vetting, organization,
     * and plan usage into one summary object.
     */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();

        $summary = [
            'usage' => $this->usage($user),
            'cases' => $this->cases($user),
            'organization' => $this->organization($user),
            'tasks' => $this->tasks($user),
            'drafts' => $this->drafts($request),
            'vetting' => $this->vetting($user),
        ];

        return response()->json(['data' => $summary]);
    }

    /**
     * Mirror the usage meters the subscription endpoint already reports, so the
     * Dashboard's tiles and the sidebar agree on the same numbers.
     */
    private function usage($user): array
    {
        $onTrial = $user->subscription?->onTrial() ?? false;

        $meter = function (string $key) use ($user, $onTrial): array {
            return [
                'used' => $onTrial
                    ? PlanLimits::organizationUsed($user, $key)
                    : PlanLimits::used($user, $key),
                'limit' => PlanLimits::limitFor($user, $key),
            ];
        };

        return [
            'messages' => $meter('messages_used'),
            'documents' => $meter('documents_uploaded'),
            'active_cases' => [
                'used' => $user->cases()
                    ->where('status', '!=', 'closed')
                    ->whereNull('archived_at')
                    ->count(),
                'limit' => PlanLimits::limitFor($user, 'active_cases'),
            ],
        ];
    }

    /**
     * Active (non-archived) cases, with how many are still open work.
     */
    private function cases($user): array
    {
        $active = $user->cases()->whereNull('archived_at');

        return [
            'total' => (clone $active)->count(),
            'open' => (clone $active)->where('status', '!=', 'closed')->count(),
        ];
    }

    /**
     * Member count and the seat pool, or zeros for a personal workspace.
     */
    private function organization($user): array
    {
        $org = $user->organization;

        if ($org === null) {
            return ['members' => 0, 'seats_used' => 0, 'seats_total' => 0];
        }

        return [
            'members' => $org->users()->count(),
            'seats_used' => $org->seatsUsed(),
            'seats_total' => $org->subscription?->seats_purchased ?? 0,
        ];
    }

    /**
     * Task counts grouped by status for the user's conversations.
     */
    private function tasks($user): array
    {
        $counts = $user->todos()
            ->toBase()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'open' => (int) (($counts['pending'] ?? 0) + ($counts['on_going'] ?? 0)),
            'pending' => (int) ($counts['pending'] ?? 0),
            'on_going' => (int) ($counts['on_going'] ?? 0),
            'completed' => (int) ($counts['completed'] ?? 0),
        ];
    }

    /**
     * Drafted letters: a total plus the most recent few for the deep-link list.
     */
    private function drafts(Request $request): array
    {
        $conversations = Conversation::query()->visibleTo($request->user());

        $recent = $conversations
            ->with([
                'messages' => fn ($query) => $query
                    ->where('role', MessageRole::Assistant)
                    ->whereNotNull('metadata->letter_draft')
                    ->latest(),
            ])
            ->latest('updated_at')
            ->get()
            ->flatMap->messages
            ->sortByDesc(fn (Message $message) => $message->created_at)
            ->take(5);

        $total = $conversations
            ->withCount([
                'messages as draft_count' => fn ($query) => $query
                    ->where('role', MessageRole::Assistant)
                    ->whereNotNull('metadata->letter_draft'),
            ])
            ->get()
            ->sum('draft_count');

        $recentItems = $recent->map(fn (Message $message) => [
            'message_id' => $message->id,
            'title' => is_string($message->metadata['letter_draft']['title'] ?? null)
                ? $message->metadata['letter_draft']['title']
                : $message->draftTitle(),
            'created_at' => $message->created_at?->toIso8601String(),
        ])->values();

        return [
            'total' => (int) $total,
            'recent' => $recentItems,
        ];
    }

    /**
     * The submitter's vetting requests grouped by status, with how many are
     * still open and actionable.
     */
    private function vetting($user): array
    {
        $requests = $user->vettingRequests()->get();

        $byStatus = $requests
            ->groupBy(fn ($request) => $request->status->value)
            ->map(fn ($group) => $group->count());

        $active = $requests->filter(fn ($request) => $request->status->isOpen())->count();

        return [
            'active' => $active,
            'by_status' => $byStatus,
        ];
    }
}
