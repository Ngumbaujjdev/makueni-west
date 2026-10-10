<?php

namespace App\Approval\Services;

use App\Models\ApprovalDelegation;
use App\Models\ApprovalRequest;
use App\Models\User;
use App\Support\PlaceRoles;

/**
 * "While I'm away, X approves for me." One hop only; never to the person
 * asking; a delegation for this kind of document beats one for everything.
 * Applied when someone is assigned (or escalated to), not afterwards - and
 * only to someone who can approve at the request's place (Handover).
 */
final class DelegationResolver
{
    /** @return array{0: User, 1: ?int} the person who acts, and who they act for (null when themselves) */
    public function resolve(User $approver, ApprovalRequest $request): array
    {
        $now = now();
        $d = ApprovalDelegation::with('delegate')->where('delegator_id', $approver->id)->where('is_active', true)
            ->where('starts_at', '<=', $now)->where('ends_at', '>=', $now)
            ->where(fn ($q) => $q->whereNull('subject_type')->orWhere('subject_type', $request->subject_type))
            ->orderByRaw('subject_type IS NULL')->orderByDesc('id')->first();
        $to = $d?->delegate;
        if (! $to || $to->id === $approver->id || (int) $to->id === (int) $request->requested_by || ! PlaceRoles::usable($to)) {
            return [$approver, null];
        }
        // Only to someone who can approve at this place (or the place above) - never across to another church.
        $place = \App\Models\Territory::find($request->territory_id);
        if (! $place || ! app(Handover::class)->eligible($to, $place)) {
            \Illuminate\Support\Facades\Log::info("Hand-over from user {$approver->id} to {$to->id} ignored on request {$request->id}: they can't approve at that place.");

            return [$approver, null];
        }

        return [$to, $approver->id];
    }
}
