<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Notifications\ApprovalActionedNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class ApprovalNotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $notifications = $request->user()->notifications()->where('type', ApprovalActionedNotification::class);

        return response()->json([
            'unread_count' => (clone $notifications)->whereNull('read_at')->count(),
            ...$notifications->latest()->paginate(25)->through(fn (DatabaseNotification $notification): array => [
                'id' => $notification->id,
                'title' => $notification->data['title'],
                'body' => $notification->data['body'],
                'status' => $notification->data['status'],
                'step_id' => $notification->data['step_id'],
                'created_at' => $notification->created_at->toIso8601String(),
                'read_at' => $notification->read_at?->toIso8601String(),
            ])->toArray(),
        ]);
    }

    public function read(Request $request, string $notification): JsonResponse
    {
        $request->user()->notifications()->where('type', ApprovalActionedNotification::class)
            ->findOrFail($notification)->markAsRead();

        return response()->json(['message' => 'Notification marked as read.']);
    }
}
