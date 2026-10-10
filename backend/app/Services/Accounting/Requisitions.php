<?php

namespace App\Services\Accounting;

use App\Approval\Services\ApprovalService;
use App\Approval\Services\Inbox;
use App\Models\PaymentVoucher;
use App\Models\Requisition;
use App\Models\StaffAdvance;
use App\Models\Territory;
use App\Models\User;
use App\Services\Settings\Settings;
use App\Support\AccountingAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Requisitions (docs/specs/accounting-spec.md, A4): asking for money in one
 * short step. It goes through the approval rules (or, with none, anyone who
 * authorises payments but the person asking); once approved the treasurer
 * makes the payment - a voucher already authorised, because the money was
 * approved here. An advance is paid into Staff advances and accounted for
 * later.
 */
final class Requisitions
{
    public function __construct(private Documents $docs, private Numbering $numbering, private ApprovalService $engine, private PaymentVouchers $vouchers, private StaffAdvances $advances) {}

    public function create(Territory $place, User $user, array $data): Requisition
    {
        $fields = $this->check($place, $user, $data);

        return DB::transaction(function () use ($place, $user, $fields) {
            $r = Requisition::create($fields + [
                'territory_id' => $place->id,
                'number' => $this->numbering->next($place, 'requisition', (int) now()->year),
                'requested_by' => $user->id,
                'status' => 'submitted',
            ]);
            $this->engine->route($r, $user);

            return $r->fresh();
        });
    }

    /** Fix it after it was sent back (or while it waits) - it goes through approval again. */
    public function update(Requisition $r, User $user, array $data): Requisition
    {
        if (! in_array($r->status, ['submitted', 'returned'], true)) {
            throw ValidationException::withMessages(['status' => ['Only a requisition waiting or sent back can be changed.']]);
        }
        if ((int) $r->requested_by !== (int) $user->id) {
            throw ValidationException::withMessages(['requisition' => ['Only the person who asked can change it.']]);
        }
        $fields = $this->check(Territory::findOrFail($r->territory_id), $user, $data, $r);

        return DB::transaction(function () use ($r, $user, $fields) {
            if ($pending = $this->engine->current($r)) {
                $this->engine->cancel($pending, $user);
            }
            $r->update($fields + ['status' => 'submitted', 'decision_note' => null, 'decided_by' => null, 'decided_at' => null]);
            $this->engine->route($r->fresh(), $user);

            return $r->fresh();
        });
    }

    /** The engine's decision. */
    public function outcome(Requisition $r, string $outcome, ?User $by, ?string $comment): void
    {
        $status = ['approved' => 'approved', 'rejected' => 'rejected', 'returned' => 'returned', 'cancelled' => 'cancelled'][$outcome] ?? null;
        if ($status) {
            $r->update(['status' => $status, 'decided_by' => $by?->id, 'decided_at' => now(), 'decision_note' => $comment ? mb_substr($comment, 0, 255) : null]);
        }
    }

    /**
     * Approve, reject or send back. Through the engine when it has a request
     * (only the person whose turn it is); with no rule for it, anyone who
     * authorises payments here - never the person asking.
     */
    public function decide(Requisition $r, User $user, string $decision, ?string $comment): Requisition
    {
        if ($request = $this->engine->current($r)) {
            $turn = app(Inbox::class)->myTurn($user, $request);
            if (! $turn) {
                throw ValidationException::withMessages(['requisition' => [(int) $r->requested_by === (int) $user->id ? 'You asked for it - someone else must approve it.' : 'It waits for someone else\'s approval.']]);
            }
            $this->engine->decide($turn, $user, $decision, $comment);

            return $r->fresh();
        }
        if ($r->status !== 'submitted') {
            throw ValidationException::withMessages(['status' => ['It isn\'t waiting for approval.']]);
        }
        $place = Territory::findOrFail($r->territory_id);
        if (! AccountingAccess::can($user, $place, 'authorise')) {
            throw ValidationException::withMessages(['requisition' => ['Your role can\'t approve requisitions here.']]);
        }
        if ((int) $r->requested_by === (int) $user->id) {
            throw ValidationException::withMessages(['requisition' => ['You asked for it - someone else must approve it.']]);
        }
        if (in_array($decision, ['reject', 'return'], true) && ! trim((string) $comment)) {
            throw ValidationException::withMessages(['comment' => ['Say why.']]);
        }
        $this->outcome($r, ['approve' => 'approved', 'reject' => 'rejected', 'return' => 'returned'][$decision], $user, $comment);

        return $r->fresh();
    }

    public function cancel(Requisition $r, User $user): Requisition
    {
        if (! in_array($r->status, ['submitted', 'returned', 'approved'], true) || $r->payment_voucher_id) {
            throw ValidationException::withMessages(['status' => ['It can\'t be cancelled now - a payment was made for it.']]);
        }

        return DB::transaction(function () use ($r, $user) {
            if ($pending = $this->engine->current($r)) {
                $this->engine->cancel($pending, $user);
            }
            $r->update(['status' => 'cancelled']);

            return $r->fresh();
        });
    }

    /** The treasurer makes the payment: a voucher already authorised, ready to pay. */
    public function makePayment(Requisition $r, User $user, int $payFromAccountId): PaymentVoucher
    {
        if ($r->status !== 'approved') {
            throw ValidationException::withMessages(['status' => ['Only an approved requisition can be paid.']]);
        }
        if ($r->payment_voucher_id) {
            throw ValidationException::withMessages(['requisition' => ['A payment voucher was already made for it.']]);
        }
        if (Procurement::mustOrder($r)) {
            throw ValidationException::withMessages(['requisition' => ['Above KES '.number_format(Procurement::oneQuoteLimit()).' a purchase goes through an order: get '.Procurement::quotesNeeded().' quotations, then raise the order.']]);
        }
        $place = Territory::findOrFail($r->territory_id);
        $requester = User::find($r->requested_by);
        $line = $r->kind === 'advance'
            ? ['account_id' => app(Chart::class)->account('staff_advances')->id, 'amount' => $r->amount, 'description' => "Advance to {$requester?->full_name}: {$r->purpose}"]
            : ['account_id' => $r->account_id, 'fund_id' => $r->fund_id, 'budget_line_id' => $r->budget_line_id, 'amount' => $r->amount, 'description' => $r->purpose];

        return DB::transaction(function () use ($r, $user, $place, $requester, $line, $payFromAccountId) {
            $pv = $this->vouchers->prepareAuthorised($place, $user, [
                'date' => now()->toDateString(),
                'payee_name' => $r->kind === 'advance' ? ($requester?->full_name ?? 'Staff advance') : ($r->payee_name ?: ($requester?->full_name ?? 'Payee')),
                'payee_phone' => $r->kind === 'advance' ? $requester?->phone : $r->payee_phone,
                'payee' => $r->kind === 'advance' ? null : $r->payee,
                'pay_from_account_id' => $payFromAccountId,
                'narration' => "{$r->number}: {$r->purpose}",
                'purpose' => $r->kind === 'advance' ? 'advance' : 'payment',
                'requisition_id' => $r->id,
                'lines' => [$line],
            ], $r->decided_by, "Requisition {$r->number} approved");
            $r->update(['payment_voucher_id' => $pv->id]);

            return $pv;
        });
    }

    /** Its payment voucher was paid: done - and an advance starts. */
    public function paid(PaymentVoucher $pv, User $user): void
    {
        $r = Requisition::find($pv->requisition_id);
        if (! $r) {
            return;
        }
        $r->update(['status' => 'paid']);
        if ($r->kind === 'advance') {
            $this->advances->issue($r, $pv);
        }
    }

    private function check(Territory $place, User $user, array $data, ?Requisition $existing = null): array
    {
        $kind = in_array($data['kind'] ?? 'payment', ['payment', 'purchase', 'advance'], true) ? $data['kind'] : 'payment';
        $amount = $this->docs->amount($data['amount'] ?? 0, 'amount');
        $purpose = trim((string) ($data['purpose'] ?? ''));
        if ($purpose === '') {
            throw ValidationException::withMessages(['purpose' => ['Say what the money is for.']]);
        }
        $account = null;
        if ($kind !== 'advance') {
            if (empty($data['account_id'])) {
                throw ValidationException::withMessages(['account_id' => ['Pick what it will be spent on.']]);
            }
            $account = $this->docs->postable($place, (int) $data['account_id'], 'account_id');
            if ($account->isCash()) {
                throw ValidationException::withMessages(['account_id' => ['Pick what it will be spent on, not where the money is kept.']]);
            }
        } else {
            $overdue = StaffAdvance::where('user_id', $user->id)->where('status', 'open')->where('due_on', '<', now()->toDateString())->where('id', '!=', $existing?->id ?? 0)->first();
            if ($overdue) {
                throw ValidationException::withMessages(['kind' => ['You have an advance of KES '.number_format((float) $overdue->amount, 2).' not accounted for since '.$overdue->due_on->format('j M').' - account for it first.']]);
            }
        }
        if (! empty($data['needed_by']) && strtotime($data['needed_by']) < strtotime('today')) {
            throw ValidationException::withMessages(['needed_by' => ['That date has passed.']]);
        }

        return [
            'kind' => $kind,
            'purpose' => mb_substr($purpose, 0, 255),
            'amount' => $amount,
            'needed_by' => $data['needed_by'] ?? null,
            'account_id' => $account?->id,
            'budget_line_id' => $account ? $this->docs->budgetLine($place, $account, $data['budget_line_id'] ?? null, 'budget_line_id') : null,
            'fund_id' => $this->docs->fund($data['fund_id'] ?? null, 'fund_id'),
            'payee_name' => isset($data['payee_name']) ? (mb_substr(trim((string) $data['payee_name']), 0, 150) ?: null) : null,
            'payee_phone' => isset($data['payee_phone']) ? (mb_substr(trim((string) $data['payee_phone']), 0, 30) ?: null) : null,
            'payee' => \App\Support\PayTo::from($data['payee'] ?? null),
        ];
    }

    public static function advanceDays(): int
    {
        return max(1, (int) (app(Settings::class)->system('approvals.advance_days') ?? 14));
    }
}
