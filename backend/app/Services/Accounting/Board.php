<?php

namespace App\Services\Accounting;

use App\Models\AccountingAccount;
use App\Models\BankReconciliation;
use App\Models\CashCount;
use App\Models\Territory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The reconciliation board (docs/specs/accounting-spec.md, A2): for a region
 * or the diocese, every place below and each of its money accounts - the
 * balance, when it was last counted or reconciled, how many months that is
 * behind, what waits, and the last month closed. Worked out in a handful of
 * grouped queries, however many churches there are.
 */
final class Board
{
    public function __construct(private Periods $periods) {}

    /** @param array<int, int> $placeIds */
    public function for(array $placeIds): array
    {
        if (! $placeIds) {
            return [];
        }
        $places = Territory::whereIn('id', $placeIds)->whereIn('territory_type', ['region', 'church'])->where('is_active', true)
            ->orderByRaw("FIELD(territory_type, 'region', 'church')")->orderBy('name')->get();
        $ids = $places->pluck('id')->all();
        $parents = Territory::whereIn('id', $places->pluck('parent_territory_id')->filter())->pluck('name', 'id');

        $sums = DB::table('journal_lines as l')->join('accounting_accounts as a', 'a.id', '=', 'l.account_id')
            ->whereIn('l.territory_id', $ids)->whereNotNull('a.cash_kind')
            ->groupBy('l.territory_id', 'l.account_id')
            ->selectRaw('l.territory_id, l.account_id, SUM(l.debit) - SUM(l.credit) AS balance, MIN(l.date) AS first_on')
            ->get()->groupBy('territory_id');
        $own = AccountingAccount::whereIn('territory_id', $ids)->whereNotNull('cash_kind')->where('is_active', true)->get()->groupBy('territory_id');
        $accounts = AccountingAccount::whereIn('id', $sums->flatten()->pluck('account_id')->merge($own->flatten()->pluck('id'))->unique())->get()->keyBy('id');

        $counts = CashCount::whereIn('territory_id', $ids)->whereIn('status', ['balanced', 'approved'])
            ->groupBy('territory_id', 'account_id')->selectRaw('territory_id, account_id, MAX(counted_on) AS last_on')->get()
            ->mapWithKeys(fn ($r) => ["{$r->territory_id}:{$r->account_id}" => $r->last_on]);
        $recs = BankReconciliation::whereIn('territory_id', $ids)->where('status', 'approved')
            ->groupBy('territory_id', 'account_id')->selectRaw('territory_id, account_id, MAX(statement_date) AS last_on')->get()
            ->mapWithKeys(fn ($r) => ["{$r->territory_id}:{$r->account_id}" => $r->last_on]);
        $waitingCounts = CashCount::whereIn('territory_id', $ids)->where('status', 'waiting')->groupBy('territory_id')->selectRaw('territory_id, COUNT(*) AS n')->pluck('n', 'territory_id');
        $waitingRecs = BankReconciliation::whereIn('territory_id', $ids)->where('status', 'submitted')->groupBy('territory_id')->selectRaw('territory_id, COUNT(*) AS n')->pluck('n', 'territory_id');
        $closed = $this->periods->lastClosed($ids);

        $lastMonthEnd = CarbonImmutable::today()->startOfMonth()->subDay();
        $rows = [];
        foreach ($places as $p) {
            $lines = ($sums[$p->id] ?? collect())->keyBy('account_id');
            $accIds = $lines->keys()->merge(($own[$p->id] ?? collect())->pluck('id'))->unique();
            $list = [];
            foreach ($accIds as $aid) {
                $a = $accounts[$aid] ?? null;
                if (! $a) {
                    continue;
                }
                $balance = round((float) ($lines[$aid]->balance ?? 0), 2);
                $first = $lines[$aid]->first_on ?? null;
                $counted = Periods::isCounted($a);
                $last = ($counted ? $counts : $recs)["{$p->id}:{$aid}"] ?? null;
                // Months behind: whole months since the last check, up to last month's end.
                $from = $last ? CarbonImmutable::parse($last)->startOfMonth()->addMonth() : ($first ? CarbonImmutable::parse($first)->startOfMonth() : null);
                $behind = $from && $from->lte($lastMonthEnd) ? (int) $from->diffInMonths($lastMonthEnd->startOfMonth()) + 1 : 0;
                $list[] = [
                    'id' => $a->id, 'name' => $a->name, 'kind' => $a->cash_kind, 'balance' => $balance,
                    'check' => $counted ? 'count' : 'reconcile', 'last_on' => $last ? CarbonImmutable::parse($last)->toDateString() : null,
                    'behind' => $behind, 'used' => (bool) $first,
                ];
            }
            $waiting = (int) ($waitingCounts[$p->id] ?? 0) + (int) ($waitingRecs[$p->id] ?? 0);
            $behind = collect($list)->where('used', true)->max('behind') ?? 0;
            $lc = $closed[$p->id] ?? null;
            $rows[] = [
                'place' => ['id' => $p->id, 'name' => $p->name, 'level' => $p->territory_type->value, 'parent' => $parents[$p->parent_territory_id] ?? null],
                'accounts' => $list,
                'held' => round(collect($list)->sum('balance'), 2),
                'waiting' => $waiting,
                'behind' => $behind,
                'last_closed' => $lc,
                'last_closed_label' => $lc ? CarbonImmutable::createFromFormat('Y-m-d', "{$lc}-01")->format('M Y') : null,
                'started' => collect($list)->contains('used', true),
                'state' => ! collect($list)->contains('used', true) ? 'none' : ($behind > 1 || $waiting ? 'late' : ($behind === 1 ? 'due' : 'ok')),
            ];
        }

        return $rows;
    }
}
