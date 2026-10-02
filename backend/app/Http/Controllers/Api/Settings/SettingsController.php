<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Controller;
use App\Models\Territory;
use App\Support\SettingsAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shared plumbing for the Settings hub's controllers
 * (docs/specs/settings-spec.md): which place, and the response shape -
 * {success, status, message, data} with a real HTTP status.
 */
abstract class SettingsController extends Controller
{
    /** The place being set up, or a 403 response (no level, or someone else's place). */
    protected function place(Request $request): Territory|JsonResponse
    {
        $territoryId = $request->query('territory_id');
        $place = SettingsAccess::place($request->user(), $territoryId !== null && ctype_digit((string) $territoryId) ? (int) $territoryId : null);

        return $place ?? $this->forbidden("These aren't your settings.");
    }

    /** null when allowed, else the 403 to return. */
    protected function deny(Request $request, Territory $place, string $section, string $action = 'read'): ?JsonResponse
    {
        return SettingsAccess::can($request->user(), $place, $section, $action)
            ? null
            : $this->forbidden($action === 'read' ? "Your role can't see this part of Settings." : "Your role can't change this part of Settings.");
    }

    protected function ok(mixed $data, string $message = 'OK', int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'status' => $status, 'message' => $message, 'data' => $data], $status);
    }

    protected function forbidden(string $message): JsonResponse
    {
        return response()->json(['success' => false, 'status' => 403, 'message' => $message], 403);
    }
}
