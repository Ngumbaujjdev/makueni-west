<?php

namespace App\Services\Accounting;

use App\Models\AccountingAccount;
use App\Models\AccountingPeriod;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\Territory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The books (docs/specs/accounting-spec.md): every document becomes one
 * balanced journal in one place's books. The same engine for a church, a
 * region and the diocese - the place decides whose books.
 *
 * Posting checks that the debits equal the credits to the cent, that every
 * account may be used by this place (a standard one or its own, active,
 * not a header), and that the month is open. A posted journal is never
 * changed or deleted; a mistake is reversed with its mirror image.
 */
final class Ledger
{
    public function __construct(private Chart $chart, private Numbering $numbering) {}

    /**
     * @param  array{doc_type: string, date: string, narration?: ?string, party_name?: ?string, party_phone?: ?string, method?: ?string, reference?: ?string, source_type?: ?string, source_id?: ?int, reverses_id?: ?int}  $head
     * @param  array<int, array{account_id: int, debit?: numeric, credit?: numeric, fund_id?: ?int, budget_line_id?: ?int, memo?: ?string, for_territory_id?: ?int}>  $lines
     */
    public function post(Territory $place, array $head, array $lines, ?User $by, bool $allowInactive = false): Journal
    {
        $date = CarbonImmutable::parse($head['date'])->startOfDay();
        // A year's closing journal (and its reversal on reopening) is dated 31 December, inside the closed months (A9).
        if (($head['source_type'] ?? null) !== 'accounting_year') {
            $this->assertOpen($place, $date);
        }
        $clean = $this->checkLines($place, $lines, $allowInactive);
        $total = array_sum(array_column($clean, 'debit_cents'));

        return DB::transaction(function () use ($place, $head, $clean, $by, $date, $total) {
            $journal = Journal::create([
                'territory_id' => $place->id,
                'number' => $this->numbering->next($place, $head['doc_type'], (int) $date->year),
                'doc_type' => $head['doc_type'],
                'date' => $date->toDateString(),
                'narration' => $this->text($head['narration'] ?? null),
                'party_name' => $this->text($head['party_name'] ?? null, 150),
                'party_phone' => $this->text($head['party_phone'] ?? null, 30),
                'method' => $head['method'] ?? null,
                'reference' => $this->text($head['reference'] ?? null, 100),
                'amount' => $total / 100,
                'source_type' => $head['source_type'] ?? null,
                'source_id' => $head['source_id'] ?? null,
                'status' => 'posted',
                'reverses_id' => $head['reverses_id'] ?? null,
                'posted_by' => $by?->id,
                'posted_at' => now(),
            ]);
            $general = $this->chart->generalFund()->id;
            foreach ($clean as $n => $l) {
                JournalLine::create([
                    'journal_id' => $journal->id,
                    'territory_id' => $place->id,
                    'date' => $journal->date->toDateString(),
                    'line_no' => $n + 1,
                    'account_id' => $l['account_id'],
                    'fund_id' => $l['fund_id'] ?: $general,
                    'budget_line_id' => $l['budget_line_id'],
                    'debit' => $l['debit_cents'] / 100,
                    'credit' => $l['credit_cents'] / 100,
                    'memo' => $this->text($l['memo']),
                    'for_territory_id' => $l['for_territory_id'],
                ]);
            }

            return $journal->load('lines.account');
        });
    }

    /** Post the mirror image of a journal, dated today (or $date), and mark it reversed. */
    public function reverse(Journal $journal, ?User $by, string $reason, ?string $date = null): Journal
    {
        if ($journal->status === 'reversed') {
            throw ValidationException::withMessages(['journal' => ["{$journal->number} has already been reversed."]]);
        }
        if ($journal->doc_type === 'reversal') {
            throw ValidationException::withMessages(['journal' => ['A reversal can\'t be reversed - post the document again instead.']]);
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => ['Say why it is being reversed.']]);
        }
        $date ??= now()->toDateString();
        if (CarbonImmutable::parse($date)->lt($journal->date)) {
            throw ValidationException::withMessages(['date' => ['A reversal can\'t be dated before the document it reverses.']]);
        }

        return DB::transaction(function () use ($journal, $by, $reason, $date) {
            $journal = Journal::whereKey($journal->id)->lockForUpdate()->firstOrFail();
            if ($journal->status === 'reversed') {
                throw ValidationException::withMessages(['journal' => ["{$journal->number} has already been reversed."]]);
            }
            $mirror = $journal->lines()->get()->map(fn (JournalLine $l) => [
                'account_id' => $l->account_id,
                'fund_id' => $l->fund_id,
                'budget_line_id' => $l->budget_line_id,
                'debit' => $l->credit,
                'credit' => $l->debit,
                'memo' => $l->memo,
                'for_territory_id' => $l->for_territory_id,
            ])->all();
            $reversal = $this->post(Territory::findOrFail($journal->territory_id), [
                'doc_type' => 'reversal',
                'date' => $date,
                'narration' => "Reverses {$journal->number}: {$reason}",
                'party_name' => $journal->party_name,
                'party_phone' => $journal->party_phone,
                'method' => $journal->method,
                'reference' => $journal->reference,
                'source_type' => $journal->source_type,
                'source_id' => $journal->source_id,
                'reverses_id' => $journal->id,
            ], $mirror, $by, true);
            $journal->update(['status' => 'reversed', 'reversed_by_id' => $reversal->id, 'reverse_reason' => mb_substr($reason, 0, 255)]);

            return $reversal;
        });
    }

    /** An account's balance in a place's books, on its normal side (assets and expenses: debits less credits). */
    public function balance(Territory $place, AccountingAccount $account, ?string $upTo = null, ?string $before = null): float
    {
        $row = JournalLine::where('territory_id', $place->id)->where('account_id', $account->id)
            ->when($upTo, fn ($q) => $q->where('date', '<=', $upTo))
            ->when($before, fn ($q) => $q->where('date', '<', $before))
            ->selectRaw('COALESCE(SUM(debit), 0) AS d, COALESCE(SUM(credit), 0) AS c')->first();
        $net = round((float) $row->d - (float) $row->c, 2);

        return $account->isDebitNormal() ? $net : -$net;
    }

    /** Balances of many accounts at once: account_id => balance on its normal side. */
    public function balances(Territory $place, Collection $accounts, ?string $upTo = null): array
    {
        $sums = JournalLine::where('territory_id', $place->id)->whereIn('account_id', $accounts->pluck('id'))
            ->when($upTo, fn ($q) => $q->where('date', '<=', $upTo))
            ->groupBy('account_id')->selectRaw('account_id, SUM(debit) AS d, SUM(credit) AS c')->get()->keyBy('account_id');
        $out = [];
        foreach ($accounts as $a) {
            $net = round((float) ($sums[$a->id]->d ?? 0) - (float) ($sums[$a->id]->c ?? 0), 2);
            $out[$a->id] = $a->isDebitNormal() ? $net : -$net;
        }

        return $out;
    }

    /**
     * The trial balance on a date: every account the place has used, with its
     * debit or credit balance; the two totals are equal when the books balance.
     */
    public function trialBalance(Territory $place, ?string $upTo = null): array
    {
        $rows = JournalLine::where('territory_id', $place->id)
            ->when($upTo, fn ($q) => $q->where('date', '<=', $upTo))
            ->groupBy('account_id')->selectRaw('account_id, SUM(debit) AS d, SUM(credit) AS c')->get();
        $accounts = AccountingAccount::whereIn('id', $rows->pluck('account_id'))->get()->keyBy('id');
        $lines = [];
        $td = $tc = 0;
        foreach ($rows as $r) {
            $net = (int) round((float) $r->d * 100) - (int) round((float) $r->c * 100);
            if ($net === 0) {
                continue;
            }
            $a = $accounts[$r->account_id];
            $lines[] = ['account_id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'type' => $a->type, 'debit' => $net > 0 ? $net / 100 : 0, 'credit' => $net < 0 ? -$net / 100 : 0];
            $net > 0 ? $td += $net : $tc -= $net;
        }
        usort($lines, fn ($a, $b) => strcmp($a['code'], $b['code']));

        return ['lines' => $lines, 'debit' => $td / 100, 'credit' => $tc / 100, 'balanced' => $td === $tc];
    }

    /** Nothing posts into a month that has been closed. */
    public function assertOpen(Territory $place, CarbonImmutable $date): void
    {
        $closed = AccountingPeriod::where('territory_id', $place->id)->where('year', $date->year)->where('month', $date->month)->where('status', 'closed')->exists();
        if ($closed) {
            throw ValidationException::withMessages(['date' => [$date->format('F Y').' is closed in the books. Date it in an open month, or ask the finance officer to reopen it.']]);
        }
    }

    public function isOpen(Territory $place, CarbonImmutable $date): bool
    {
        return ! AccountingPeriod::where('territory_id', $place->id)->where('year', $date->year)->where('month', $date->month)->where('status', 'closed')->exists();
    }

    /** Amounts in cents, one side per line, every account usable here, and the two sides equal. */
    private function checkLines(Territory $place, array $lines, bool $allowInactive): array
    {
        $lines = array_values(array_filter($lines, fn ($l) => round((float) ($l['debit'] ?? 0), 2) != 0 || round((float) ($l['credit'] ?? 0), 2) != 0));
        if (count($lines) < 2) {
            throw ValidationException::withMessages(['lines' => ['A journal needs at least two lines - money from one account to another.']]);
        }
        $accounts = AccountingAccount::whereIn('id', array_map(fn ($l) => (int) $l['account_id'], $lines))->get()->keyBy('id');
        $clean = [];
        $d = $c = 0;
        foreach ($lines as $i => $l) {
            $debit = (int) round((float) ($l['debit'] ?? 0) * 100);
            $credit = (int) round((float) ($l['credit'] ?? 0) * 100);
            if ($debit < 0 || $credit < 0 || ($debit > 0 && $credit > 0)) {
                throw ValidationException::withMessages(["lines.{$i}" => ['Each line is either a debit or a credit, never both or less than nothing.']]);
            }
            $a = $accounts[(int) $l['account_id']] ?? null;
            if (! $a || ($a->territory_id !== null && (int) $a->territory_id !== (int) $place->id)) {
                throw ValidationException::withMessages(["lines.{$i}" => ['One of the accounts isn\'t in these books.']]);
            }
            if ($a->is_header) {
                throw ValidationException::withMessages(["lines.{$i}" => ["{$a->code} {$a->name} only groups other accounts - pick one under it."]]);
            }
            if (! $a->is_active && ! $allowInactive) {
                throw ValidationException::withMessages(["lines.{$i}" => ["{$a->name} is switched off."]]);
            }
            $clean[] = [
                'account_id' => $a->id,
                'debit_cents' => $debit,
                'credit_cents' => $credit,
                'fund_id' => ! empty($l['fund_id']) ? (int) $l['fund_id'] : null,
                'budget_line_id' => ! empty($l['budget_line_id']) ? (int) $l['budget_line_id'] : null,
                'memo' => $l['memo'] ?? null,
                'for_territory_id' => ! empty($l['for_territory_id']) ? (int) $l['for_territory_id'] : null,
            ];
            $d += $debit;
            $c += $credit;
        }
        if ($d !== $c) {
            throw ValidationException::withMessages(['lines' => ['The debits (KES '.number_format($d / 100, 2).') and credits (KES '.number_format($c / 100, 2).') must be equal.']]);
        }

        return $clean;
    }

    private function text(?string $s, int $max = 255): ?string
    {
        $s = $s === null ? null : trim($s);

        return $s === '' || $s === null ? null : mb_substr($s, 0, $max);
    }
}
