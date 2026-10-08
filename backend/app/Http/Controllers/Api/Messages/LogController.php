<?php

namespace App\Http\Controllers\Api\Messages;

use App\Http\Controllers\Api\Settings\MessagesController as SettingsMessagesController;
use App\Models\Territory;
use App\Support\MessagesAccess;
use App\Support\PlaceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Communication > Messages > Message log (docs/specs/messages-spec.md):
 * every email and SMS sent for a place - the same list, preview and resend
 * as Settings > Communication's log, for those who read or send messages
 * (no Settings rights needed). A church sees its own; a region its
 * churches' too; the diocese everything. Resend is for senders.
 */
class LogController extends SettingsMessagesController
{
    protected function place(Request $request): Territory|JsonResponse
    {
        $id = $request->query('territory_id');
        $place = PlaceAccess::place($request->user(), $id !== null && ctype_digit((string) $id) ? (int) $id : null);

        return $place ?? $this->forbidden("This isn't your place.");
    }

    protected function gate(Request $request, Territory $place, bool $resend = false): ?JsonResponse
    {
        return MessagesAccess::can($request->user(), $place, $resend ? 'send' : 'read')
            ? null
            : $this->forbidden($resend ? "Your role can't send messages here." : "Your role can't see the message log.");
    }

    protected function mayResend(Request $request, Territory $place): bool
    {
        return MessagesAccess::can($request->user(), $place, 'send');
    }
}
