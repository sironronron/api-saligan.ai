<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Notifications\DatabaseNotification;

class NotificationController extends Controller
{
    /**
     * List the authenticated user's notifications, newest first.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $request->user()->notifications();

        if ($request->boolean('unread')) {
            $query->whereNull('read_at');
        }

        return NotificationResource::collection($query->latest()->paginate(50))
            ->additional([
                'unread_count' => $request->user()->notifications()->whereNull('read_at')->count(),
            ]);
    }

    /**
     * The unread count the navbar badge polls, so the bell never needs to
     * download the whole feed to stay accurate.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'unread_count' => $request->user()->notifications()->whereNull('read_at')->count(),
        ]);
    }

    /**
     * Mark a notification as read or unread.
     */
    public function markRead(Request $request, DatabaseNotification $notification): NotificationResource
    {
        abort_unless($this->owns($request, $notification), 403);

        $validated = $request->validate([
            'read' => ['required', 'boolean'],
        ]);

        $notification->update(['read_at' => $validated['read'] ? now() : null]);

        return new NotificationResource($notification);
    }

    /**
     * Mark every unread notification as read.
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $count = $request->user()->notifications()
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'message' => 'Notifications marked as read',
            'read_count' => $count,
        ]);
    }

    /**
     * Remove a notification from the feed.
     */
    public function destroy(Request $request, DatabaseNotification $notification): JsonResponse
    {
        abort_unless($this->owns($request, $notification), 403);

        $notification->delete();

        return response()->json(['message' => 'Notification deleted']);
    }

    /**
     * Notifications are scoped to the user they were delivered to.
     */
    protected function owns(Request $request, DatabaseNotification $notification): bool
    {
        return $notification->notifiable_type === User::class
            && $notification->notifiable_id === $request->user()->id;
    }
}
