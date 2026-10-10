<?php

namespace App\Services\Accounting;

use App\Models\AccountingAccount;
use App\Models\AccountingFund;
use App\Models\BudgetLine;
use App\Models\Journal;
use App\Models\Territory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The documents a treasurer writes (docs/specs/accounting-spec.md) - an
 * official receipt, a transfer between the place's own accounts, a journal
 * voucher - each turned into its balanced journal. The same for every
 * level; the place is whose books.
 */
final class Documents
{
    public function __construct(private Chart $chart, private Ledger $ledger, private BudgetBridge $bridge) {}

    /**
     * Money received into one of the place's cash, bank or M-Pesa accounts,
     * for one or more things (Tithes 1,000 + Building fund 500).
     *
     * @param  array{date: string, account_id: int, party_name: string, party_phone?: ?string, method?: ?string, reference?: ?string, narration?: ?string, lines: array<int, array{account_id: int, amount: numeric, fund_id?: ?int, budget_line_id?: ?int, memo?: ?string}>}  $data
     */
    public function receipt(Territory $place, User $user, array $data): Journal
    {
        $this->notFuture($data['date']);
        $into = $this->cashAccount($place, (int) $data['account_id'], 'account_id');
        $lines = [];
        $total = 0;
        foreach ($data['lines'] as $i => $l) {
            $account = $this->postable($place, (int) $l['account_id'], "lines.{$i}.account_id");
            if ($account->isCash()) {
                throw ValidationException::withMessages(["lines.{$i}.account_id" => ['Money moving between your own accounts is a transfer, not a receipt.']]);
            }
            $amount = $this->amount($l['amount'], "lines.{$i}.amount");
            $total += $amount;
            $lines[] = [
                'account_id' => $account->id,
                'credit' => $amount,
                'fund_id' => $this->fund($l['fund_id'] ?? null, "lines.{$i}.fund_id"),
                'budget_line_id' => $this->budgetLine($place, $account, $l['budget_line_id'] ?? null, "lines.{$i}.budget_line_id"),
                'memo' => $l['memo'] ?? null,
            ];
        }
        array_unshift($lines, ['account_id' => $into->id, 'debit' => round($total, 2)]);

        return DB::transaction(function () use ($place, $user, $data, $into, $lines) {
            $journal = $this->ledger->post($place, [
                'doc_type' => 'receipt',
                'date' => $data['date'],
                'narration' => $data['narration'] ?? null,
                'party_name' => $data['party_name'],
                'party_phone' => $data['party_phone'] ?? null,
                'method' => $data['method'] ?? $this->methodFor($into),
                'reference' => $data['reference'] ?? null,
            ], $lines, $user);
            $this->bridge->journalPosted($journal, $user);

            return $journal;
        });
    }

    /**
     * Money moved between two of the place's own accounts - banking the
     * Sunday cash, topping up petty cash, withdrawing from the bank. Not
     * income or spending, so the totals don't move.
     */
    public function transfer(Territory $place, User $user, array $data): Journal
    {
        $this->notFuture($data['date']);
        $from = $this->cashAccount($place, (int) $data['from_account_id'], 'from_account_id');
        $to = $this->cashAccount($place, (int) $data['to_account_id'], 'to_account_id');
        if ($from->id === $to->id) {
            throw ValidationException::withMessages(['to_account_id' => ['Pick two different accounts.']]);
        }
        $amount = $this->amount($data['amount'], 'amount');

        return $this->ledger->post($place, [
            'doc_type' => 'transfer',
            'date' => $data['date'],
            'narration' => $data['narration'] ?? "{$from->name} to {$to->name}",
            'method' => $to->cash_kind === 'bank' || $from->cash_kind === 'bank' ? 'bank' : ($to->cash_kind === 'mpesa' || $from->cash_kind === 'mpesa' ? 'mpesa' : 'cash'),
            'reference' => $data['reference'] ?? null,
        ], [
            ['account_id' => $to->id, 'debit' => $amount],
            ['account_id' => $from->id, 'credit' => $amount],
        ], $user);
    }

    /**
     * Any balanced lines - opening balances, corrections, moving money
     * between funds. For whoever keeps the books.
     *
     * @param  array{date: string, narration: string, lines: array<int, array{account_id: int, debit?: numeric, credit?: numeric, fund_id?: ?int, memo?: ?string}>}  $data
     */
    public function journalVoucher(Territory $place, User $user, array $data): Journal
    {
        $this->notFuture($data['date']);
        $lines = [];
        foreach ($data['lines'] as $i => $l) {
            $account = $this->postable($place, (int) $l['account_id'], "lines.{$i}.account_id");
            $lines[] = [
                'account_id' => $account->id,
                'debit' => round((float) ($l['debit'] ?? 0), 2),
                'credit' => round((float) ($l['credit'] ?? 0), 2),
                'fund_id' => $this->fund($l['fund_id'] ?? null, "lines.{$i}.fund_id"),
                'memo' => $l['memo'] ?? null,
            ];
        }

        return $this->ledger->post($place, ['doc_type' => 'journal', 'date' => $data['date'], 'narration' => $data['narration']], $lines, $user);
    }

    /** Reverse a document; anything it put into a budget comes off too. */
    public function reverse(Journal $journal, User $user, string $reason, ?string $date = null): Journal
    {
        if ($journal->source_type === 'budget_entry') {
            throw ValidationException::withMessages(['journal' => ['This came from Budgets - change or remove the entry there and the books follow.']]);
        }
        if ($journal->source_type === 'payment_voucher') {
            throw ValidationException::withMessages(['journal' => ['This is a paid payment voucher - open the voucher to reverse it.']]);
        }
        if ($journal->source_type === 'collection') {
            throw ValidationException::withMessages(['journal' => ['This is a Sunday collection - open it under Collections to reverse it.']]);
        }
        if ($journal->source_type === 'supplier_invoice') {
            throw ValidationException::withMessages(['journal' => ['This is a supplier\'s bill - open it under Procurement to reverse it.']]);
        }
        if ($journal->source_type === 'advance_retirement') {
            throw ValidationException::withMessages(['journal' => ['This accounts for a staff advance - correct it with a journal, so the advance keeps its figures.']]);
        }

        return DB::transaction(function () use ($journal, $user, $reason, $date) {
            $reversal = $this->ledger->reverse($journal, $user, $reason, $date);
            $this->bridge->journalReversed($journal, $user);
            if ($journal->source_type === 'collection_banking') {
                // The cash is back where it was kept - the collection can be banked again.
                \App\Models\Collection::whereKey($journal->source_id)->where('banking_journal_id', $journal->id)->update(['banking_journal_id' => null]);
            }

            return $reversal;
        });
    }

    // ------------------------------------------------------------- checks

    public function notFuture(string $date): void
    {
        if (CarbonImmutable::parse($date)->startOfDay()->gt(CarbonImmutable::today())) {
            throw ValidationException::withMessages(['date' => ['The date can\'t be in the future.']]);
        }
    }

    /** One of the place's money accounts (cash, petty cash, its banks and M-Pesa). */
    public function cashAccount(Territory $place, int $id, string $field): AccountingAccount
    {
        $a = $this->postable($place, $id, $field);
        if (! $a->isCash()) {
            throw ValidationException::withMessages([$field => ['Pick cash, a bank or an M-Pesa account.']]);
        }

        return $a;
    }

    /** An account this place can post to: standard or its own, active, not a header. */
    public function postable(Territory $place, int $id, string $field): AccountingAccount
    {
        $a = AccountingAccount::find($id);
        if (! $a || ($a->territory_id !== null && (int) $a->territory_id !== (int) $place->id)) {
            throw ValidationException::withMessages([$field => ['That account isn\'t in these books.']]);
        }
        if ($a->is_header) {
            throw ValidationException::withMessages([$field => ["{$a->name} only groups other accounts - pick one under it."]]);
        }
        if (! $a->is_active) {
            throw ValidationException::withMessages([$field => ["{$a->name} is switched off."]]);
        }

        return $a;
    }

    public function amount(mixed $value, string $field): float
    {
        $amount = round((float) $value, 2);
        if ($amount <= 0) {
            throw ValidationException::withMessages([$field => ['Enter an amount above zero.']]);
        }
        if ($amount > 999999999999.99) {
            throw ValidationException::withMessages([$field => ['That amount is too large.']]);
        }

        return $amount;
    }

    public function fund(mixed $id, string $field): ?int
    {
        if ($id === null || $id === '') {
            return null;
        }
        if (! AccountingFund::whereKey((int) $id)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages([$field => ['Pick one of the funds.']]);
        }

        return (int) $id;
    }

    /**
     * The budget line the money counts against: the one asked for (it must be
     * this place's and post to this account), else the place's line for the
     * account - so Budgets sees it without anyone choosing.
     */
    public function budgetLine(Territory $place, AccountingAccount $account, mixed $id, string $field): ?int
    {
        if (! in_array($account->type, ['income', 'expense'], true)) {
            return null;
        }
        if ($id !== null && $id !== '') {
            $line = BudgetLine::forPlace($place->territory_type->value, $place->id)->find((int) $id);
            $ours = (bool) $line;
            if (! $ours || ($line->account_id && (int) $line->account_id !== (int) $account->id) || (! $line->account_id && $this->chart->forBudgetLine($line)->id !== $account->id)) {
                throw ValidationException::withMessages([$field => ['That budget line doesn\'t go with this account.']]);
            }

            return $line->id;
        }

        return $this->chart->budgetLineFor($place, $account->id)?->id;
    }

    public function methodFor(AccountingAccount $account): string
    {
        return match ($account->cash_kind) {
            'mpesa' => 'mpesa',
            'bank' => 'bank',
            default => 'cash',
        };
    }
}
