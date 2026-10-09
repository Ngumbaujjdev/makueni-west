<?php

namespace App\Approval\Contracts;

use App\Models\ApprovalRequest;
use App\Models\Territory;
use App\Models\User;

/**
 * A document that goes through approval (docs/specs/accounting-spec.md, A4).
 * The engine routes it by its place and context, shows its summary in the
 * Approvals inbox and tells it the outcome - inside the same transaction.
 */
interface Approvable
{
    /** Whose books it belongs to - approvers are found relative to this place. */
    public function approvalPlace(): Territory;

    /** What the workflow conditions look at: amount, kind, document... */
    public function approvalContext(): array;

    /** {type, label, number, title, amount, page, param} for the inbox and notices. */
    public function approvalSummary(): array;

    /**
     * What an approver needs to see, wherever they sit - the place's own pages
     * may not be theirs: {facts: [[label, value]], lines: [[label, amount]], media: Media[]}.
     */
    public function approvalDetails(): array;

    /** approved | rejected | returned | cancelled. */
    public function approvalOutcome(ApprovalRequest $request, string $outcome, ?User $by, ?string $comment): void;
}
