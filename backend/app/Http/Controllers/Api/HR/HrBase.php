<?php

namespace App\Http\Controllers\Api\HR;

use App\Http\Controllers\Controller;
use App\Models\Territory;
use App\Support\HrAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** What the Staff controllers share (docs/specs/hr-spec.md): the place a request is for, and the replies. */
abstract class HrBase extends Controller
{
    /** The place, when the user may see its staff; changing needs $ability there too. */
    protected function place(Request $request, ?string $ability = null): Territory|JsonResponse
    {
        $id = $request->input('territory_id', $request->query('territory_id'));
        $place = HrAccess::place($request->user(), $id !== null && ctype_digit((string) $id) ? (int) $id : null);
        if (! $place) {
            return $this->forbidden('These staff aren\'t yours to see.');
        }
        if ($ability && ! HrAccess::can($request->user(), $place, $ability)) {
            return $this->forbidden($ability === 'setup' ? 'Your role can\'t change the positions and pay here.' : 'Your role can\'t change staff here.');
        }

        return $place;
    }

    protected function placeInfo(Territory $place): array
    {
        return ['id' => $place->id, 'name' => $place->name, 'level' => $place->territory_type->value];
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
}
