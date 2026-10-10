<?php

namespace App\Services\Accounting;

use App\Models\AccountingAccount;
use App\Models\AccountingFund;
use App\Models\AccountingPeriod;
use App\Models\AccountingYear;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\Territory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Year-end close (docs/specs/accounting-spec.md, A9). Once all twelve months
 * of a year are closed, the year is closed: one closing journal dated 31
 * December empties each income and expense account and moves each fund's
 * surplus (or deficit) into that fund's own account (General fund 3000,
 * Building fund 3100...). The next year starts with income and spending at
 * nothing. Years close in order; the level above reopens one, with a reason,
 * which reverses the closing journal.
 */
final class Years
{
    public function __construct(private Ledger $ledger, private Chart $chart) {}

    /** The years with postings, newest first, each with whether it can be closed and what stops it. */
    public function list(Territory $place): array
    {
        $first = JournalLine::where('territory_id', $place->id)->min('date');
        if (! $first) {
            return [];
        }
        $rows = AccountingYear::where('territory_id', $place->id)->get()->keyBy('year');
        $out = [];
        for ($y = CarbonImmutable::today()->year; $y >= (int) substr($first, 0, 4); $y--) {
            $r = $rows->get($y);
            $closed = $r?->status === 'closed';
            $out[] = [
                'year' => $y,
                'status' => $closed ? 'closed' : 'open',
                'surplus' => $r?->surplus !== null ? (float) $r->surplus : null,
                'closing_journal' => $closed && $r->closing_journal_id ? Journal::find($r->closing_journal_id)?->only(['id', 'number']) : null,
                'closed_by' => $closed && $r->closed_by ? User::find($r->closed_by)?->full_name : null,
                'closed_at' => $closed ? $r->closed_at?->toIso8601String() : null,
                'reopened_at' => $r?->reopened_at?->toIso8601String(),
                'reopen_reason' => $r?->reopen_reason,
                'blockers' => $closed ? [] : $this->blockers($place, $y),
            ];
        }

        return $out;
    }

    /** What stands between a year and closing it: not ended, months still open, the year before still open. */
    public function blockers(Territory $place, int $year): array
    {
        $end = CarbonImmutable::create($year, 12, 31);
        if (! $end->lt(CarbonImmutable::today())) {
            return ["{$year} hasn't ended yet."];
        }
        $out = [];
        $first = JournalLine::where('territory_id', $place->id)->min('date');
        $from = $first && substr($first, 0, 4) === (string) $year ? (int) substr($first, 5, 2) : 1;
        $closed = AccountingPeriod::where('territory_id', $place->id)->where('year', $year)->where('status', 'closed')->pluck('month')->map(fn ($m) => (int) $m)->all();
        $open = array_values(array_diff(range($from, 12), $closed));
        if ($open) {
            $names = array_map(fn ($m) => CarbonImmutable::create($year, $m, 1)->format('F'), $open);
            $out[] = count($names) === 1 ? "Close {$names[0]} first." : 'Close '.count($names).' months first ('.implode(', ', array_slice($names, 0, 3)).(count($names) > 3 ? '...' : '').').';
        }
        $earlier = $year - 1;
        if ($first && (int) substr($first, 0, 4) <= $earlier && ! AccountingYear::where('territory_id', $place->id)->where('year', $earlier)->where('status', 'closed')->exists()) {
            $out[] = "Close {$earlier} first - years close in order.";
        }

        return $out;
    }

    public function close(Territory $place, User $user, int $year): AccountingYear
    {
        if (AccountingYear::where('territory_id', $place->id)->where('year', $year)->where('status', 'closed')->exists()) {
            throw ValidationException::withMessages(['year' => ["{$year} is already closed."]]);
        }
        if ($blockers = $this->blockers($place, $year)) {
            throw ValidationException::withMessages(['year' => $blockers]);
        }

        return DB::transaction(function () use ($place, $user, $year) {
            $row = AccountingYear::firstOrCreate(['territory_id' => $place->id, 'year' => $year]);
            $row = AccountingYear::whereKey($row->id)->lockForUpdate()->firstOrFail();
            if ($row->status === 'closed') {
                throw ValidationException::withMessages(['year' => ["{$year} is already closed."]]);
            }
            [$lines, $surplus] = $this->closingLines($place, $year);
            $journal = $lines ? $this->ledger->post($place, [
                'doc_type' => 'closing',
                'date' => "{$year}-12-31",
                'narration' => "Year-end close {$year}: the year's income and spending moved into the funds",
                'source_type' => 'accounting_year',
                'source_id' => $row->id,
            ], $lines, $user, true) : null;
            $row->update(['status' => 'closed', 'closing_journal_id' => $journal?->id, 'surplus' => $surplus, 'closed_by' => $user->id, 'closed_at' => now()]);

            return $row->fresh();
        });
    }

    public function reopen(Territory $place, User $user, int $year, string $reason): AccountingYear
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => ['Say why it is being reopened.']]);
        }

        return DB::transaction(function () use ($place, $user, $year, $reason) {
            $row = AccountingYear::where('territory_id', $place->id)->where('year', $year)->lockForUpdate()->first();
            if (! $row || $row->status !== 'closed') {
                throw ValidationException::withMessages(['year' => ["{$year} isn't closed."]]);
            }
            if (AccountingYear::where('territory_id', $place->id)->where('year', '>', $year)->where('status', 'closed')->exists()) {
                throw ValidationException::withMessages(['year' => ['Reopen the later closed years first - the latest one comes first.']]);
            }
            if ($row->closing_journal_id && ($journal = Journal::find($row->closing_journal_id)) && $journal->status !== 'reversed') {
                $this->ledger->reverse($journal, $user, "Year {$year} reopened: {$reason}", "{$year}-12-31");
            }
            $row->update(['status' => 'open', 'closing_journal_id' => null, 'surplus' => null, 'reopened_by' => $user->id, 'reopened_at' => now(), 'reopen_reason' => mb_substr($reason, 0, 255)]);

            return $row->fresh();
        });
    }

    /** Is the year of this month closed? (A closed year's months stay closed until the year is reopened.) */
    public function isClosed(Territory $place, int $year): bool
    {
        return AccountingYear::where('territory_id', $place->id)->where('year', $year)->where('status', 'closed')->exists();
    }

    /**
     * Each income and expense account emptied, per fund, and each fund's net
     * into its own account. @return array{0: array, 1: float} lines and the surplus
     */
    private function closingLines(Territory $place, int $year): array
    {
        $sums = JournalLine::where('territory_id', $place->id)->whereBetween('date', ["{$year}-01-01", "{$year}-12-31"])
            ->whereIn('account_id', AccountingAccount::whereIn('type', ['income', 'expense'])->select('id'))
            ->groupBy('account_id', 'fund_id')->selectRaw('account_id, fund_id, SUM(debit) AS d, SUM(credit) AS c')->get();
        $general = $this->chart->generalFund();
        $funds = AccountingFund::whereNotNull('equity_account_id')->get()->keyBy('id');
        $lines = [];
        $byFund = [];
        foreach ($sums as $s) {
            $cents = (int) round((float) $s->d * 100) - (int) round((float) $s->c * 100);
            if ($cents === 0) {
                continue;
            }
            $fund = (int) ($s->fund_id ?: $general->id);
            // A debit balance (spending) is emptied with a credit, a credit balance (income) with a debit.
            $lines[] = ['account_id' => (int) $s->account_id, 'fund_id' => $fund, $cents > 0 ? 'credit' : 'debit' => abs($cents) / 100, 'memo' => "Closed into the fund for {$year}"];
            $byFund[$fund] = ($byFund[$fund] ?? 0) - $cents;
        }
        foreach ($byFund as $fund => $cents) {
            if ($cents === 0) {
                continue;
            }
            $equity = ($funds[$fund] ?? $funds[$general->id] ?? null)?->equity_account_id ?? $this->chart->account('general_fund')->id;
            $lines[] = ['account_id' => (int) $equity, 'fund_id' => $fund, $cents > 0 ? 'credit' : 'debit' => abs($cents) / 100, 'memo' => ($cents > 0 ? 'Surplus' : 'Deficit')." for {$year}"];
        }

        return [$lines, array_sum($byFund) / 100];
    }
}
