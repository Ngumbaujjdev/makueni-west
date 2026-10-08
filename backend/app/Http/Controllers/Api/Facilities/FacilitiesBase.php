<?php

namespace App\Http\Controllers\Api\Facilities;

use App\Http\Controllers\Controller;
use App\Models\Territory;
use App\Services\Facilities\Facilities;
use App\Support\PeopleAccess;
use App\Support\PlaceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What every Facilities controller shares (docs/specs/people-and-care-spec.md,
 * P5): the church the user acts for - facilities are each church's own, and
 * another church is refused - and the replies.
 */
abstract class FacilitiesBase extends Controller
{
    public function __construct(protected Facilities $facilities) {}

    /** The church, when the user may read (or book / manage) its facilities. */
    protected function church(Request $request, string $ability = 'read'): Territory|JsonResponse
    {
        $id = $request->query('territory_id');
        $place = PlaceAccess::place($request->user(), $id !== null && ctype_digit((string) $id) ? (int) $id : null);
        if (! $place || PlaceAccess::level($place) !== 'church') {
            return $this->forbidden('Facilities are kept by each church - only its own leaders see them.');
        }
        $ok = match ($ability) {
            'book' => $this->facilities->canBook($request->user(), $place),
            default => PeopleAccess::canNamed($request->user(), $place, 'facilities', $ability),
        };
        if (! $ok) {
            return $this->forbidden(match ($ability) {
                'manage' => "Your role can't change the church's facilities.",
                'book' => "Your role can't book rooms.",
                default => "Your role can't see the church's facilities.",
            });
        }

        return $place;
    }

    protected function can(Request $request, Territory $church): array
    {
        return [
            'manage' => $this->facilities->canManage($request->user(), $church),
            'book' => $this->facilities->canBook($request->user(), $church),
        ];
    }

    protected function ok(mixed $data, string $message = 'OK', int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'status' => $status, 'message' => $message, 'data' => $data], $status);
    }

    protected function forbidden(string $message): JsonResponse
    {
        return response()->json(['success' => false, 'status' => 403, 'message' => $message], 403);
    }

    protected function notFound(string $message): JsonResponse
    {
        return response()->json(['success' => false, 'status' => 404, 'message' => $message], 404);
    }

    protected function unprocessable(string $field, string $message): JsonResponse
    {
        return response()->json(['success' => false, 'status' => 422, 'message' => $message, 'errors' => [$field => [$message]]], 422);
    }
}
