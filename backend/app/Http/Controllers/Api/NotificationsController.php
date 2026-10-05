<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Notifications\PlaceNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Validation\Rule;

/**
 * The signed-in person's own in-app notifications (the header bell and the
 * Notifications page, docs/specs/events-initiatives-spec.md).
 */
class NotificationsController extends Controller
{
    /** GET /notifications?limit=&kind=&unread= - newest first, plus the unread count and counts by kind. */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'limit' => ['nullable', 'integer', 'between:1,200'],
            'kind' => ['nullable', Rule::in(array_keys(PlaceNotification::KINDS))],
            'unread' => ['nullable', 'boolean'],
        ]);
        $user = $request->user();
        $query = $user->notifications()
            ->when($data['kind'] ?? null, fn ($q, $k) => $q->where('data->kind', $k))
            ->when($request->boolean('unread'), fn ($q) => $q->whereNull('read_at'));
        $items = $query->limit($data['limit'] ?? 50)->get();

        return $this->ok([
            'items' => $items->map(fn (DatabaseNotification $n) => $this->present($n))->values(),
            'unread' => $user->unreadNotifications()->count(),
            'week' => $user->notifications()->where('created_at', '>=', now()->subDays(7))->count(),
            'by_kind' => collect(PlaceNotification::KINDS)->map(fn ($look, $kind) => [
                'label' => $look['label'], 'icon' => $look['icon'], 'colour' => $look['colour'],
                'unread' => $user->unreadNotifications()->where('data->kind', $kind)->count(),
            ]),
        ]);
    }

    /** POST /notifications/{id}/read */
    public function read(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->whereKey($id)->first();
        if (! $notification) {
            return response()->json(['success' => false, 'status' => 404, 'message' => 'That notification isn\'t yours.'], 404);
        }
        $notification->markAsRead();

        return $this->ok(['unread' => $request->user()->unreadNotifications()->count()]);
    }

    /** POST /notifications/read-all */
    public function readAll(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return $this->ok(['unread' => 0], 'All marked as read.');
    }

    private function present(DatabaseNotification $n): array
    {
        return $n->data + ['id' => $n->id, 'read' => $n->read_at !== null, 'at' => $n->created_at?->toIso8601String()];
    }

    private function ok(mixed $data, string $message = 'OK'): JsonResponse
    {
        return response()->json(['success' => true, 'status' => 200, 'message' => $message, 'data' => $data]);
    }
}
