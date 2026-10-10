<?php

namespace App\Services\Accounting;

use App\Approval\Services\ApprovalService;
use App\Approval\Services\Inbox;
use App\Models\Journal;
use App\Models\PaymentVoucher;
use App\Models\Territory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Payment vouchers (docs/specs/accounting-spec.md): the standard way money
 * leaves the books. Prepared (with what it's for and the invoice), then
 * authorised by someone else, then paid - and only paying posts it:
 * Dr what it was for, Cr the account it was paid from. A voucher that's sent
 * back can be fixed and sent again; a paid one is reversed, never cancelled.
 */
final class PaymentVouchers
{
    public function __construct(private Documents $docs, private Ledger $ledger, private Numbering $numbering, private BudgetBridge $bridge, private ApprovalService $engine) {}

    /** @param array{date: string, payee_name: string, payee_phone?: ?string, pay_from_account_id: int, narration: string, lines: array} $data */
    public function prepare(Territory $place, User $user, array $data): PaymentVoucher
    {
        [$from, $lines, $total] = $this->check($place, $data);

        return DB::transaction(function () use ($place, $user, $data, $from, $lines, $total) {
            $pv = PaymentVoucher::create([
                'territory_id' => $place->id,
                'number' => $this->numbering->next($place, 'voucher', (int) date('Y', strtotime($data['date']))),
                'date' => $data['date'],
                'payee_name' => trim($data['payee_name']),
                'payee_phone' => $data['payee_phone'] ?? null,
                'pay_from_account_id' => $from->id,
                'narration' => trim($data['narration']),
                'purpose' => in_array($data['purpose'] ?? 'payment', ['imprest_topup', 'advance', 'bill', 'remittance'], true) ? $data['purpose'] : 'payment',
                'requisition_id' => $data['requisition_id'] ?? null,
                'supplier_invoice_id' => $data['supplier_invoice_id'] ?? null,
                'remittance_id' => $data['remittance_id'] ?? null,
                'amount' => $total,
                'status' => ($data['status'] ?? 'prepared') === 'authorised' ? 'authorised' : 'prepared',
                'authorised_by' => ($data['status'] ?? '') === 'authorised' ? ($data['authorised_by'] ?? null) : null,
                'authorised_at' => ($data['status'] ?? '') === 'authorised' ? now() : null,
                'authorise_note' => $data['authorise_note'] ?? null,
                'prepared_by' => $user->id,
                'prepared_at' => now(),
            ]);
            $pv->lines()->createMany($lines);
            if ($pv->status === 'prepared') {
                // Through the approval rules; with none for it, the A1 rule (anyone who authorises but the preparer).
                $this->engine->route($pv, $user);
            }

            return $pv->fresh('lines');
        });
    }

    /**
     * A voucher for something already approved (a requisition): authorised at
     * once, by whoever approved it - nobody approves the same money twice.
     */
    public function prepareAuthorised(Territory $place, User $user, array $data, ?int $authorisedBy, string $note): PaymentVoucher
    {
        return $this->prepare($place, $user, $data + ['status' => 'authorised', 'authorised_by' => $authorisedBy, 'authorise_note' => $note]);
    }

    /** Change it while it waits, or after it was sent back (it goes back to waiting). */
    public function update(PaymentVoucher $pv, User $user, array $data): PaymentVoucher
    {
        $this->assertStatus($pv, ['prepared', 'rejected'], 'Only a voucher still waiting, or sent back, can be changed.');
        $place = Territory::findOrFail($pv->territory_id);
        [$from, $lines, $total] = $this->check($place, $data + ['purpose' => $pv->purpose]);

        return DB::transaction(function () use ($pv, $user, $data, $from, $lines, $total) {
            $pv->update([
                'date' => $data['date'],
                'payee_name' => trim($data['payee_name']),
                'payee_phone' => $data['payee_phone'] ?? null,
                'pay_from_account_id' => $from->id,
                'narration' => trim($data['narration']),
                'amount' => $total,
                'status' => 'prepared',
                'prepared_by' => $user->id,
                'prepared_at' => now(),
                'rejected_by' => null, 'rejected_at' => null,
            ]);
            $pv->lines()->delete();
            $pv->lines()->createMany($lines);
            if ($pending = $this->engine->current($pv)) {
                $this->engine->cancel($pending, $user);
            }
            $this->engine->route($pv->fresh(), $user);

            return $pv->fresh('lines');
        });
    }

    public function authorise(PaymentVoucher $pv, User $user, ?string $note = null): PaymentVoucher
    {
        $this->assertStatus($pv, ['prepared'], 'Only a voucher waiting to be authorised can be authorised.');
        if ($request = $this->engine->current($pv)) {
            $turn = app(Inbox::class)->myTurn($user, $request);
            if (! $turn) {
                throw ValidationException::withMessages(['voucher' => [(int) $pv->prepared_by === (int) $user->id ? 'You prepared this voucher - someone else must authorise it.' : 'It waits for someone else\'s approval.']]);
            }
            $this->engine->approve($turn, $user, $note);

            return $pv->fresh('lines');
        }
        if ((int) $pv->prepared_by === (int) $user->id) {
            throw ValidationException::withMessages(['voucher' => ['You prepared this voucher - someone else must authorise it.']]);
        }
        $pv->update(['status' => 'authorised', 'authorised_by' => $user->id, 'authorised_at' => now(), 'authorise_note' => $note ? mb_substr(trim($note), 0, 255) : null]);

        return $pv->fresh('lines');
    }

    public function reject(PaymentVoucher $pv, User $user, string $reason): PaymentVoucher
    {
        $this->assertStatus($pv, ['prepared', 'authorised'], 'Only a voucher that hasn\'t been paid can be sent back.');
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => ['Say what needs fixing.']]);
        }
        if ($request = $this->engine->current($pv)) {
            $turn = app(Inbox::class)->myTurn($user, $request);
            if (! $turn) {
                throw ValidationException::withMessages(['voucher' => ['It waits for someone else\'s approval.']]);
            }
            $this->engine->sendBack($turn, $user, $reason);

            return $pv->fresh('lines');
        }
        $pv->update(['status' => 'rejected', 'rejected_by' => $user->id, 'rejected_at' => now(), 'reject_reason' => mb_substr($reason, 0, 255), 'authorised_by' => null, 'authorised_at' => null]);

        return $pv->fresh('lines');
    }

    /** Pay it: posts Dr what it was for / Cr the account paid from, and the budget follows. */
    public function pay(PaymentVoucher $pv, User $user, array $data): PaymentVoucher
    {
        $this->assertStatus($pv, ['authorised'], 'Only an authorised voucher can be paid.');
        $this->docs->notFuture($data['paid_on']);
        if (strtotime($data['paid_on']) < strtotime($pv->date->toDateString())) {
            throw ValidationException::withMessages(['paid_on' => ['It can\'t be paid before the voucher\'s date.']]);
        }
        $place = Territory::findOrFail($pv->territory_id);
        $from = $this->docs->cashAccount($place, (int) $pv->pay_from_account_id, 'pay_from_account_id');

        return DB::transaction(function () use ($pv, $user, $data, $place, $from) {
            $pv = PaymentVoucher::whereKey($pv->id)->lockForUpdate()->firstOrFail();
            $this->assertStatus($pv, ['authorised'], 'Only an authorised voucher can be paid.');
            $lines = $pv->lines()->get()->map(fn ($l) => [
                'account_id' => $l->account_id, 'debit' => $l->amount, 'fund_id' => $l->fund_id, 'budget_line_id' => $l->budget_line_id, 'memo' => $l->description,
            ])->all();
            $lines[] = ['account_id' => $from->id, 'credit' => $pv->amount];
            $journal = $this->ledger->post($place, [
                // A float top-up moves money between our own accounts - a transfer, not spending.
                'doc_type' => $pv->purpose === 'imprest_topup' ? 'transfer' : 'payment',
                'date' => $data['paid_on'],
                'narration' => "{$pv->number}: {$pv->narration}",
                'party_name' => $pv->payee_name,
                'party_phone' => $pv->payee_phone,
                'method' => $data['method'] ?? $this->docs->methodFor($from),
                'reference' => $data['reference'] ?? null,
                'source_type' => 'payment_voucher',
                'source_id' => $pv->id,
            ], $lines, $user);
            $pv->update(['status' => 'paid', 'paid_by' => $user->id, 'paid_at' => now(), 'paid_on' => $data['paid_on'], 'method' => $journal->method, 'reference' => $journal->reference, 'journal_id' => $journal->id]);
            $this->bridge->journalPosted($journal, $user);
            if ($pv->requisition_id) {
                app(Requisitions::class)->paid($pv->fresh(), $user);
            }
            if ($pv->supplier_invoice_id) {
                app(Procurement::class)->voucherPaid($pv->fresh());
            }
            if ($pv->remittance_id) {
                app(Remittances::class)->voucherPaid($pv->fresh());
            }

            return $pv->fresh('lines');
        });
    }

    /** Undo a payment: its journal is reversed and the voucher is open to pay again. */
    public function reversePayment(PaymentVoucher $pv, User $user, string $reason): PaymentVoucher
    {
        $this->assertStatus($pv, ['paid'], 'Only a paid voucher can have its payment reversed.');
        if ($pv->remittance_id) {
            app(Remittances::class)->assertPaymentReversible($pv);
        }

        return DB::transaction(function () use ($pv, $user, $reason) {
            $journal = Journal::findOrFail($pv->journal_id);
            $this->ledger->reverse($journal, $user, $reason);
            $this->bridge->journalReversed($journal, $user);
            $pv->update(['status' => 'authorised', 'paid_by' => null, 'paid_at' => null, 'paid_on' => null, 'journal_id' => null]);
            if ($pv->supplier_invoice_id) {
                app(Procurement::class)->voucherUnpaid($pv);
            }
            if ($pv->remittance_id) {
                app(Remittances::class)->voucherUnpaid($pv);
            }

            return $pv->fresh('lines');
        });
    }

    public function cancel(PaymentVoucher $pv, User $user): PaymentVoucher
    {
        $this->assertStatus($pv, ['prepared', 'authorised', 'rejected'], 'A paid voucher can\'t be cancelled - reverse its payment first.');
        if ($request = $this->engine->current($pv)) {
            $this->engine->cancel($request, $user);
        }
        $pv->update(['status' => 'cancelled', 'cancelled_by' => $user->id, 'cancelled_at' => now()]);
        if ($pv->supplier_invoice_id) {
            app(Procurement::class)->voucherCancelled($pv);
        }
        if ($pv->remittance_id) {
            app(Remittances::class)->voucherCancelled($pv);
        }

        return $pv->fresh('lines');
    }

    /** @return array{0: \App\Models\AccountingAccount, 1: array, 2: float} */
    private function check(Territory $place, array $data): array
    {
        $from = $this->docs->cashAccount($place, (int) $data['pay_from_account_id'], 'pay_from_account_id');
        if (trim((string) ($data['payee_name'] ?? '')) === '') {
            throw ValidationException::withMessages(['payee_name' => ['Who is being paid?']]);
        }
        $lines = [];
        $total = 0;
        foreach ($data['lines'] ?? [] as $i => $l) {
            $account = $this->docs->postable($place, (int) $l['account_id'], "lines.{$i}.account_id");
            $topUp = ($data['purpose'] ?? 'payment') === 'imprest_topup' && $account->cash_kind === 'petty_cash' && $account->id !== $from->id;
            if ($account->isCash() && ! $topUp) {
                throw ValidationException::withMessages(["lines.{$i}.account_id" => ['Moving money between your own accounts is a transfer, not a payment.']]);
            }
            $amount = $this->docs->amount($l['amount'], "lines.{$i}.amount");
            $total += $amount;
            $lines[] = [
                'account_id' => $account->id,
                'fund_id' => $this->docs->fund($l['fund_id'] ?? null, "lines.{$i}.fund_id"),
                'budget_line_id' => $this->docs->budgetLine($place, $account, $l['budget_line_id'] ?? null, "lines.{$i}.budget_line_id"),
                'description' => isset($l['description']) ? mb_substr(trim((string) $l['description']), 0, 255) : null,
                'amount' => $amount,
            ];
        }
        if (! $lines) {
            throw ValidationException::withMessages(['lines' => ['Add what is being paid for.']]);
        }

        return [$from, $lines, round($total, 2)];
    }

    private function assertStatus(PaymentVoucher $pv, array $allowed, string $message): void
    {
        if (! in_array($pv->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => [$message]]);
        }
    }
}
