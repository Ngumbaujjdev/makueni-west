<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * A write sent again with the same Idempotency-Key gets the first answer back
 * and changes nothing (docs/specs/mobile-app-spec.md, M11). The phone app's
 * outbox puts one key on each saved form, so a count sent twice - a retry after
 * the reply was lost - is recorded once. Without the header nothing changes.
 * Only successful answers are kept, so a corrected retry still goes through.
 */
class Idempotent
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = trim((string) $request->header('Idempotency-Key', ''));
        $user = $request->user();
        if ($key === '' || ! $user) {
            return $next($request);
        }
        if (strlen($key) > 64) {
            return errorResponse('The Idempotency-Key is too long.', 422);
        }

        $kept = DB::table('idempotency_keys')->where('key', $key)->where('user_id', $user->id)->first();
        if ($kept) {
            return response($kept->response_body, $kept->status_code, ['Content-Type' => 'application/json', 'Idempotent-Replay' => 'true']);
        }

        $response = $next($request);

        $body = $response->getContent();
        $data = $response instanceof JsonResponse ? $response->getData(true) : json_decode((string) $body, true);
        $ok = $response->getStatusCode() < 400 && is_array($data) && ($data['success'] ?? true) !== false;
        if ($ok) {
            DB::table('idempotency_keys')->insertOrIgnore([
                'key' => $key, 'user_id' => $user->id, 'method' => $request->method(),
                'route' => substr($request->path(), 0, 191), 'status_code' => $response->getStatusCode(),
                'response_body' => (string) $body, 'created_at' => now(),
            ]);
        }

        return $response;
    }
}
