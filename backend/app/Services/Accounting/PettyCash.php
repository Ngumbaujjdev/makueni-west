<?php

namespace App\Services\Accounting;

use App\Models\AccountingAccount;
use App\Models\AccountingPlaceAccount;
use App\Models\Journal;
use App\Models\PaymentVoucher;
use App\Models\Territory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Petty cash on a fixed float (docs/specs/accounting-spec.md, A2), the
 * imprest system: the custodian holds, say, KES 5,000; small spends go on
 * petty cash vouchers with their receipts; the float is topped back up for
 * exactly what was spent, through a payment voucher (authorised, then paid
 * from the bank). At any moment, cash in hand + vouchers since the last
 * top-up = the float.
 */
final class PettyCash
{
    public function __construct(private Chart $chart, private Ledger $ledger, private Documents $docs, private PaymentVouchers $vouchers, private BudgetBridge $bridge) {}

    public function account(Territory $place, ?int $id = null): AccountingAccount
    {
        $a = $id ? $this->docs->cashAccount($place, $id, 'account_id') : $this->chart->account('petty_cash');
        if ($a->cash_kind !== 'petty_cash') {
            throw ValidationException::withMessages(['account_id' => ['That isn\'t a petty cash account.']]);
        }

        return $a;
    }

    public function settings(Territory $place, AccountingAccount $a): ?AccountingPlaceAccount
    {
        return AccountingPlaceAccount::where('territory_id', $place->id)->where('account_id', $a->id)->first();
    }

    /** Set the float and who keeps it. */
    public function setFloat(Territory $place, AccountingAccount $a, float $float, ?int $custodianId): AccountingPlaceAccount
    {
        if ($float <= 0) {
            throw ValidationException::withMessages(['imprest_float' => ['The float must be more than nothing.']]);
        }

        return AccountingPlaceAccount::updateOrCreate(['territory_id' => $place->id, 'account_id' => $a->id], ['imprest_float' => round($float, 2), 'custodian_id' => $custodianId]);
    }

    /** Where the float stands: float, cash in hand, spent since the last top-up, what a top-up would be. */
    public function status(Territory $place, ?AccountingAccount $a = null): array
    {
        $a ??= $this->account($place);
        $s = $this->settings($place, $a);
        $balance = $this->ledger->balance($place, $a);
        $float = $s?->imprest_float !== null ? (float) $s->imprest_float : null;
        $lastTopUp = Journal::where('territory_id', $place->id)->where('doc_type', 'transfer')->where('source_type', 'payment_voucher')
            ->whereIn('source_id', PaymentVoucher::where('territory_id', $place->id)->where('purpose', 'imprest_topup')->pluck('id'))
            ->where('status', 'posted')->max('date');
        $spent = Journal::with('lines')->where('territory_id', $place->id)->where('doc_type', 'petty_cash')->where('status', 'posted')
            ->when($lastTopUp, fn ($q) => $q->where('date', '>', $lastTopUp))->get();
        $pending = PaymentVoucher::where('territory_id', $place->id)->where('purpose', 'imprest_topup')->whereIn('status', ['prepared', 'authorised'])->first();

        return [
            'account' => ['id' => $a->id, 'name' => $a->name],
            'float' => $float,
            'custodian' => $s?->custodian ? ['id' => $s->custodian->id, 'name' => $s->custodian->full_name] : null,
            'balance' => $balance,
            'vouchers_since' => $spent->count(),
            'spent_since' => round($spent->sum('amount'), 2),
            'last_top_up' => $lastTopUp,
            'top_up' => $float !== null ? max(0, round($float - $balance, 2)) : null,
            'pending_top_up' => $pending ? ['id' => $pending->id, 'number' => $pending->number, 'status' => $pending->status, 'amount' => (float) $pending->amount] : null,
        ];
    }

    /**
     * A petty cash voucher: a small spend with its receipt, Dr what it was
     * for / Cr petty cash - never more than is in the box.
     *
     * @param  array{date: string, account_id?: ?int, payee: string, narration?: ?string, lines: array<int, array{account_id: int, amount: numeric, fund_id?: ?int, memo?: ?string}>}  $data
     */
    public function spend(Territory $place, User $user, array $data): Journal
    {
        $this->docs->notFuture($data['date']);
        $box = $this->account($place, $data['account_id'] ?? null);
        $lines = [];
        $total = 0;
        foreach ($data['lines'] as $i => $l) {
            $account = $this->docs->postable($place, (int) $l['account_id'], "lines.{$i}.account_id");
            if ($account->isCash()) {
                throw ValidationException::withMessages(["lines.{$i}.account_id" => ['Petty cash pays for things - money between accounts is a transfer.']]);
            }
            $amount = $this->docs->amount($l['amount'], "lines.{$i}.amount");
            $total += $amount;
            $lines[] = [
                'account_id' => $account->id,
                'debit' => $amount,
                'fund_id' => $this->docs->fund($l['fund_id'] ?? null, "lines.{$i}.fund_id"),
                'budget_line_id' => $this->docs->budgetLine($place, $account, $l['budget_line_id'] ?? null, "lines.{$i}.budget_line_id"),
                'memo' => $l['memo'] ?? null,
            ];
        }
        $total = round($total, 2);
        $inBox = min($this->ledger->balance($place, $box, $data['date']), $this->ledger->balance($place, $box));
        if ($total - $inBox > 0.004) {
            throw ValidationException::withMessages(['lines' => ['Petty cash holds only KES '.number_format(max(0, $inBox), 2).' - top up the float first.']]);
        }
        $lines[] = ['account_id' => $box->id, 'credit' => $total];

        return DB::transaction(function () use ($place, $user, $data, $lines) {
            $journal = $this->ledger->post($place, [
                'doc_type' => 'petty_cash',
                'date' => $data['date'],
                'narration' => $data['narration'] ?? null,
                'party_name' => trim($data['payee']),
                'method' => 'cash',
            ], $lines, $user);
            $this->bridge->journalPosted($journal, $user);

            return $journal;
        });
    }

    /** Prepare the payment voucher that tops the float back up - exactly what was spent. */
    public function topUp(Territory $place, User $user, int $fromAccountId): PaymentVoucher
    {
        $box = $this->account($place);
        $st = $this->status($place, $box);
        if ($st['float'] === null) {
            throw ValidationException::withMessages(['imprest_float' => ['Set the petty cash float first.']]);
        }
        if ($st['pending_top_up']) {
            throw ValidationException::withMessages(['from_account_id' => ["Top-up {$st['pending_top_up']['number']} is already waiting."]]);
        }
        if ($st['top_up'] <= 0) {
            throw ValidationException::withMessages(['from_account_id' => ['Petty cash is full - nothing to top up.']]);
        }

        return $this->vouchers->prepare($place, $user, [
            'date' => now()->toDateString(),
            'payee_name' => $st['custodian']['name'] ?? 'Petty cash custodian',
            'pay_from_account_id' => $fromAccountId,
            'narration' => 'Top up petty cash to its float of KES '.number_format($st['float'], 2)." ({$st['vouchers_since']} vouchers)",
            'purpose' => 'imprest_topup',
            'lines' => [['account_id' => $box->id, 'amount' => $st['top_up'], 'description' => 'Petty cash float']],
        ]);
    }
}
