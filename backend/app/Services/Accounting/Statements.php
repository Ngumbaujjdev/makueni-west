<?php

namespace App\Services\Accounting;

use App\Models\AccountingAccount;
use App\Models\AccountingFund;
use App\Models\Territory;
use App\Support\PlaceAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The financial statements (docs/specs/accounting-spec.md, A9), read straight
 * from the journal lines, for one place's books or - consolidated - for a
 * region or the diocese with every place below it:
 *
 * - income and expenditure for a period, a column per fund, beside the same
 *   period last year;
 * - financial position (what is owned, owed and held in each fund) at a date;
 * - receipts and payments - the cash in and out, opening to closing;
 * - changes in funds - each fund's opening, income, spending, transfers and
 *   closing balance;
 * - the trial balance, before or after the year-end close.
 *
 * Consolidated, money that moved between two places in the set (a share sent
 * up, support sent down, paybill money held for a church) is taken out on
 * both sides: a line naming another place of the set (for_territory_id) is
 * left out. What one side has posted and the other not yet (money on its
 * way) shows as one line, so the statements still balance.
 *
 * Income and expenditure never counts a year's closing journal: closing
 * moves the year's surplus into the funds, it isn't income or spending.
 */
final class Statements
{
    /** @var array<int, int>|null equity account id => fund id */
    private ?array $byEquity = null;

    /** The places a statement covers: the place alone, or with every place below it. @return int[] */
    public function places(Territory $place, bool $consolidated): array
    {
        return $consolidated ? array_values(array_unique([(int) $place->id, ...PlaceAccess::descendantIds($place)])) : [(int) $place->id];
    }

    // ------------------------------------------------------------ income and expenditure

    public function incomeExpenditure(Territory $place, string $from, string $to, bool $consolidated = false): array
    {
        $ids = $this->places($place, $consolidated);
        [$lyFrom, $lyTo] = $this->lastYear($from, $to);
        $now = $this->sums($ids, $from, $to, false, true);
        $before = $this->sums($ids, $lyFrom, $lyTo, false)->keyBy('account_id');
        $accounts = $this->accounts([...$now->pluck('account_id'), ...$before->keys()]);
        $funds = $this->funds();

        $rows = ['income' => [], 'expense' => []];
        $used = [];
        foreach ($now->groupBy('account_id') as $accountId => $parts) {
            $a = $accounts[$accountId] ?? null;
            if (! $a || ! isset($rows[$a->type])) {
                continue;
            }
            $byFund = [];
            foreach ($parts as $p) {
                $amount = $this->normal($a, $p->d, $p->c);
                $fund = (int) ($p->fund_id ?: $funds->first()->id);
                $byFund[$fund] = round(($byFund[$fund] ?? 0) + $amount, 2);
                $used[$fund] = true;
            }
            $rows[$a->type][$accountId] = $this->row($a, array_sum($byFund), $before->has($accountId) ? $this->normal($a, $before[$accountId]->d, $before[$accountId]->c) : 0) + ['by_fund' => $byFund];
        }
        foreach ($before as $accountId => $p) {
            $a = $accounts[$accountId] ?? null;
            if ($a && isset($rows[$a->type]) && ! isset($rows[$a->type][$accountId])) {
                $rows[$a->type][$accountId] = $this->row($a, 0, $this->normal($a, $p->d, $p->c)) + ['by_fund' => []];
            }
        }
        $income = $this->sorted($rows['income']);
        $expense = $this->sorted($rows['expense']);
        $shown = $funds->filter(fn ($f) => isset($used[$f->id]) || $f->code === 'GEN')->values();
        $byFund = $shown->mapWithKeys(function ($f) use ($income, $expense) {
            $in = round(array_sum(array_map(fn ($r) => $r['by_fund'][$f->id] ?? 0, $income)), 2);
            $out = round(array_sum(array_map(fn ($r) => $r['by_fund'][$f->id] ?? 0, $expense)), 2);

            return [$f->id => ['income' => $in, 'expense' => $out, 'surplus' => round($in - $out, 2)]];
        })->all();
        $total = fn (array $r, string $k) => round(array_sum(array_column($r, $k)), 2);

        return [
            'kind' => 'ie',
            ...$this->head($place, $ids),
            'from' => $from, 'to' => $to, 'compare' => ['from' => $lyFrom, 'to' => $lyTo],
            'funds' => $shown->map(fn ($f) => $this->fundInfo($f))->all(),
            'income' => $income,
            'expense' => $expense,
            'totals' => [
                'income' => $total($income, 'amount'), 'expense' => $total($expense, 'amount'), 'surplus' => round($total($income, 'amount') - $total($expense, 'amount'), 2),
                'by_fund' => $byFund,
                'last_year' => ['income' => $total($income, 'last_year'), 'expense' => $total($expense, 'last_year'), 'surplus' => round($total($income, 'last_year') - $total($expense, 'last_year'), 2)],
            ],
            'eliminated' => count($ids) > 1 ? $this->eliminations($ids, $from, $to, ['income', 'expense']) : [],
        ];
    }

    // ------------------------------------------------------------ financial position

    public function position(Territory $place, string $at, bool $consolidated = false): array
    {
        $ids = $this->places($place, $consolidated);
        $ly = CarbonImmutable::parse($at)->subYear()->toDateString();
        $now = $this->sums($ids, null, $at, true, true);
        $before = $this->sums($ids, null, $ly, true, true);
        $accounts = $this->accounts([...$now->pluck('account_id'), ...$before->pluck('account_id')]);
        $roll = count($ids) > 1;

        $lines = ['asset' => [], 'liability' => []];
        foreach (['amount' => $now, 'last_year' => $before] as $col => $sums) {
            foreach ($sums as $p) {
                $a = $accounts[$p->account_id] ?? null;
                if (! $a || ! isset($lines[$a->type])) {
                    continue;
                }
                $shown = $this->shownAs($a, $roll, $accounts);
                $lines[$a->type][$shown->id] ??= $this->row($shown, 0, 0);
                $lines[$a->type][$shown->id][$col] = round($lines[$a->type][$shown->id][$col] + $this->normal($a, $p->d, $p->c), 2);
            }
        }
        $transit = $roll ? $this->transit($ids, null, $at) : 0.0;
        $transitBefore = $roll ? $this->transit($ids, null, $ly) : 0.0;
        if (abs($transit) >= 0.005 || abs($transitBefore) >= 0.005) {
            $type = $transit >= 0 && $transitBefore >= 0 ? 'asset' : 'liability';
            $sign = $type === 'asset' ? 1 : -1;
            $lines[$type]['transit'] = ['account_id' => null, 'code' => '', 'name' => $type === 'asset' ? 'Money on its way between places' : 'Received from places, not yet sent by them',
                'amount' => round($sign * $transit, 2), 'last_year' => round($sign * $transitBefore, 2)];
        }
        $assets = $this->sorted($lines['asset']);
        $liabilities = $this->sorted($lines['liability']);
        $fundsNow = $this->fundBalances($now, $accounts);
        $fundsBefore = $this->fundBalances($before, $accounts);
        $funds = $this->funds()->filter(fn ($f) => isset($fundsNow[$f->id]) || isset($fundsBefore[$f->id]) || $f->code === 'GEN')
            ->map(fn ($f) => $this->fundInfo($f) + ['amount' => $fundsNow[$f->id] ?? 0.0, 'last_year' => $fundsBefore[$f->id] ?? 0.0])->values()->all();
        $sum = fn (array $r, string $k) => round(array_sum(array_column($r, $k)), 2);
        $net = round($sum($assets, 'amount') - $sum($liabilities, 'amount'), 2);

        return [
            'kind' => 'position',
            ...$this->head($place, $ids),
            'at' => $at, 'compare' => ['at' => $ly],
            'assets' => $assets,
            'liabilities' => $liabilities,
            'funds' => $funds,
            'in_transit' => $transit,
            'totals' => [
                'assets' => $sum($assets, 'amount'), 'liabilities' => $sum($liabilities, 'amount'), 'net_assets' => $net, 'funds' => $sum($funds, 'amount'),
                'balanced' => abs($net - $sum($funds, 'amount')) < 0.005,
                'last_year' => ['assets' => $sum($assets, 'last_year'), 'liabilities' => $sum($liabilities, 'last_year'), 'net_assets' => round($sum($assets, 'last_year') - $sum($liabilities, 'last_year'), 2), 'funds' => $sum($funds, 'last_year')],
            ],
        ];
    }

    // ------------------------------------------------------------ receipts and payments

    /**
     * The cash in and out: for every document that moved money through a cash,
     * bank or M-Pesa account, its other lines - credits are receipts, debits
     * payments. Money moved between our own accounts is neither.
     */
    public function receiptsPayments(Territory $place, string $from, string $to, bool $consolidated = false): array
    {
        $ids = $this->places($place, $consolidated);
        $roll = count($ids) > 1;
        $flows = $this->cashFlows($ids, $from, $to, true);
        $openingSums = $this->cashSums($ids, null, CarbonImmutable::parse($from)->subDay()->toDateString());
        $closingSums = $this->cashSums($ids, null, $to);
        $accounts = $this->accounts([...$flows->pluck('account_id'), ...$openingSums->pluck('account_id'), ...$closingSums->pluck('account_id')]);

        $receipts = $payments = [];
        foreach ($flows as $p) {
            $a = $accounts[$p->account_id] ?? null;
            if (! $a) {
                continue;
            }
            $net = round((float) $p->c - (float) $p->d, 2);
            if (abs($net) < 0.005) {
                continue;
            }
            $shown = $this->shownAs($a, $roll, $accounts);
            if ($net > 0) {
                $receipts[$shown->id] ??= $this->row($shown, 0, 0);
                $receipts[$shown->id]['amount'] = round($receipts[$shown->id]['amount'] + $net, 2);
            } else {
                $payments[$shown->id] ??= $this->row($shown, 0, 0);
                $payments[$shown->id]['amount'] = round($payments[$shown->id]['amount'] - $net, 2);
            }
        }
        // Consolidated: money sent to a place of the set and not yet received there (or the other way).
        $transit = $roll ? round((float) $this->cashFlows($ids, $from, $to, false)->sum(fn ($p) => (float) $p->c - (float) $p->d), 2) : 0.0;
        if ($transit <= -0.005) {
            $payments['transit'] = ['account_id' => null, 'code' => '', 'name' => 'Sent between places, not yet received', 'amount' => -$transit, 'last_year' => 0.0];
        } elseif ($transit >= 0.005) {
            $receipts['transit'] = ['account_id' => null, 'code' => '', 'name' => 'Received between places, not yet sent', 'amount' => $transit, 'last_year' => 0.0];
        }
        $balances = function (Collection $sums) use ($accounts, $roll) {
            $out = [];
            foreach ($sums as $p) {
                $a = $accounts[$p->account_id] ?? null;
                if (! $a) {
                    continue;
                }
                $shown = $this->shownAs($a, $roll, $accounts);
                $out[$shown->id] ??= $this->row($shown, 0, 0);
                $out[$shown->id]['amount'] = round($out[$shown->id]['amount'] + (float) $p->d - (float) $p->c, 2);
            }

            return array_values(array_filter($this->sorted($out), fn ($r) => abs($r['amount']) >= 0.005));
        };
        $opening = $balances($openingSums);
        $closing = $balances($closingSums);
        $sum = fn (array $r) => round(array_sum(array_column($r, 'amount')), 2);
        $receipts = $this->sorted($receipts);
        $payments = $this->sorted($payments);

        return [
            'kind' => 'receipts_payments',
            ...$this->head($place, $ids),
            'from' => $from, 'to' => $to,
            'opening' => $opening, 'receipts' => $receipts, 'payments' => $payments, 'closing' => $closing,
            'totals' => [
                'opening' => $sum($opening), 'receipts' => $sum($receipts), 'payments' => $sum($payments), 'closing' => $sum($closing),
                'balanced' => abs($sum($opening) + $sum($receipts) - $sum($payments) - $sum($closing)) < 0.005,
            ],
        ];
    }

    // ------------------------------------------------------------ changes in funds

    public function changesInFunds(Territory $place, string $from, string $to, bool $consolidated = false): array
    {
        $ids = $this->places($place, $consolidated);
        $dayBefore = CarbonImmutable::parse($from)->subDay()->toDateString();
        $opening = $this->sums($ids, null, $dayBefore, true, true);
        $closing = $this->sums($ids, null, $to, true, true);
        $period = $this->sums($ids, $from, $to, false, true);
        $accounts = $this->accounts([...$opening->pluck('account_id'), ...$closing->pluck('account_id'), ...$period->pluck('account_id')]);
        $open = $this->fundBalances($opening, $accounts);
        $close = $this->fundBalances($closing, $accounts);
        $general = $this->funds()->first()->id;
        $flows = [];
        foreach ($period as $p) {
            $a = $accounts[$p->account_id] ?? null;
            if (! $a || ! in_array($a->type, ['income', 'expense', 'fund'], true)) {
                continue;
            }
            $fund = $this->fundOf($a, $p->fund_id, $general);
            $key = ['income' => 'income', 'expense' => 'expense', 'fund' => 'transfers'][$a->type];
            $flows[$fund][$key] = round(($flows[$fund][$key] ?? 0) + ($a->type === 'expense' ? (float) $p->d - (float) $p->c : (float) $p->c - (float) $p->d), 2);
        }
        $rows = $this->funds()->filter(fn ($f) => isset($open[$f->id]) || isset($close[$f->id]) || isset($flows[$f->id]) || $f->code === 'GEN')
            ->map(fn ($f) => $this->fundInfo($f) + [
                'opening' => $open[$f->id] ?? 0.0,
                'income' => $flows[$f->id]['income'] ?? 0.0,
                'expense' => $flows[$f->id]['expense'] ?? 0.0,
                'surplus' => round(($flows[$f->id]['income'] ?? 0) - ($flows[$f->id]['expense'] ?? 0), 2),
                'transfers' => $flows[$f->id]['transfers'] ?? 0.0,
                'closing' => $close[$f->id] ?? 0.0,
            ])->values()->all();
        $sum = fn (string $k) => round(array_sum(array_column($rows, $k)), 2);
        $totals = array_combine(['opening', 'income', 'expense', 'surplus', 'transfers', 'closing'], array_map($sum, ['opening', 'income', 'expense', 'surplus', 'transfers', 'closing']));

        return [
            'kind' => 'funds',
            ...$this->head($place, $ids),
            'from' => $from, 'to' => $to,
            'funds' => $rows,
            'totals' => $totals + ['balanced' => abs($totals['opening'] + $totals['surplus'] + $totals['transfers'] - $totals['closing']) < 0.005],
        ];
    }

    // ------------------------------------------------------------ trial balance

    /**
     * Every account's balance at a date. Before the close leaves out the
     * closing journal of that date's year (the year's income and spending
     * still in their own accounts).
     */
    public function trialBalance(Territory $place, string $at, bool $consolidated = false, bool $beforeClose = false): array
    {
        $ids = $this->places($place, $consolidated);
        $roll = count($ids) > 1;
        $sums = $this->sums($ids, null, $at, $beforeClose ? CarbonImmutable::parse($at)->startOfYear()->toDateString() : true);
        $accounts = $this->accounts($sums->pluck('account_id'));
        $net = [];
        foreach ($sums as $p) {
            $a = $accounts[$p->account_id] ?? null;
            if (! $a) {
                continue;
            }
            $shown = $this->shownAs($a, $roll, $accounts);
            $net[$shown->id] = ($net[$shown->id] ?? 0) + (int) round((float) $p->d * 100) - (int) round((float) $p->c * 100);
        }
        if ($roll && ($t = (int) round($this->transit($ids, null, $at) * 100)) !== 0) {
            $net['transit'] = $t;
        }
        $lines = [];
        $td = $tc = 0;
        foreach ($net as $id => $cents) {
            if ($cents === 0) {
                continue;
            }
            $a = $id === 'transit' ? null : $accounts[$id];
            $lines[] = ['account_id' => $a?->id, 'code' => $a?->code ?? '', 'name' => $a?->name ?? 'Money on its way between places', 'type' => $a?->type ?? ($cents > 0 ? 'asset' : 'liability'),
                'debit' => $cents > 0 ? $cents / 100 : 0, 'credit' => $cents < 0 ? -$cents / 100 : 0];
            $cents > 0 ? $td += $cents : $tc -= $cents;
        }
        usort($lines, fn ($x, $y) => [$x['code'] === '' ? 1 : 0, $x['code']] <=> [$y['code'] === '' ? 1 : 0, $y['code']]);

        return [
            'kind' => 'trial_balance',
            ...$this->head($place, $ids),
            'at' => $at, 'before_close' => $beforeClose,
            'lines' => $lines, 'debit' => $td / 100, 'credit' => $tc / 100, 'balanced' => $td === $tc,
        ];
    }

    // ------------------------------------------------------------ reading the lines

    /**
     * Debits and credits per account (and fund), for the places, in a range.
     * $yearEnd: true counts closing journals, false leaves them out, a date
     * leaves out those dated on or after it. Consolidated, lines naming
     * another place of the set are left out.
     */
    private function sums(array $ids, ?string $from, ?string $to, bool|string $yearEnd, bool $byFund = false): Collection
    {
        $q = $this->lines($ids, $from, $to, $yearEnd);
        $this->kept($q, $ids);
        $group = $byFund ? ['l.account_id', 'l.fund_id'] : ['l.account_id'];

        return $q->groupBy($group)->select($group)->selectRaw('SUM(l.debit) AS d, SUM(l.credit) AS c')->get();
    }

    private function lines(array $ids, ?string $from, ?string $to, bool|string $yearEnd): Builder
    {
        $q = DB::table('journal_lines as l')->whereIn('l.territory_id', $ids)
            ->when($from, fn ($q) => $q->where('l.date', '>=', $from))
            ->when($to, fn ($q) => $q->where('l.date', '<=', $to));
        if ($yearEnd !== true) {
            $q->whereNotExists(fn ($w) => $w->from('journals as yj')->whereColumn('yj.id', 'l.journal_id')->where('yj.source_type', 'accounting_year')
                ->when(is_string($yearEnd), fn ($x) => $x->where('yj.date', '>=', $yearEnd)));
        }

        return $q;
    }

    /** Only the lines that don't move money to or from another place of the set. */
    private function kept(Builder $q, array $ids): void
    {
        if (count($ids) > 1) {
            $q->where(fn ($w) => $w->whereNull('l.for_territory_id')->orWhereColumn('l.for_territory_id', 'l.territory_id')->orWhereNotIn('l.for_territory_id', $ids));
        }
    }

    /** Only the lines between two places of the set. */
    private function between(Builder $q, array $ids): void
    {
        $q->whereIn('l.for_territory_id', $ids)->whereColumn('l.for_territory_id', '!=', 'l.territory_id');
    }

    /** What one side has posted and the other not yet: the lines taken out, debits less credits. */
    private function transit(array $ids, ?string $from, ?string $to): float
    {
        $q = $this->lines($ids, $from, $to, true);
        $this->between($q, $ids);

        return round((float) $q->sum('l.debit') - (float) $q->sum('l.credit'), 2);
    }

    /** What was taken out between places in a period, per account: [{code, name, type, debit, credit}]. */
    private function eliminations(array $ids, string $from, string $to, array $types): array
    {
        $q = $this->lines($ids, $from, $to, false);
        $this->between($q, $ids);
        $rows = $q->join('accounting_accounts as a', 'a.id', '=', 'l.account_id')->whereIn('a.type', $types)
            ->groupBy('a.id', 'a.code', 'a.name', 'a.type')->select('a.code', 'a.name', 'a.type')->selectRaw('SUM(l.debit) AS d, SUM(l.credit) AS c')->orderBy('a.code')->get();

        return $rows->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'type' => $r->type, 'debit' => round((float) $r->d, 2), 'credit' => round((float) $r->c, 2)])->all();
    }

    /** The non-cash lines of documents that moved money through a cash account: kept ones, or (false) those between places. */
    private function cashFlows(array $ids, string $from, string $to, bool $kept): Collection
    {
        $q = $this->lines($ids, $from, $to, true)->join('accounting_accounts as a', 'a.id', '=', 'l.account_id')->whereNull('a.cash_kind')
            ->whereExists(fn ($w) => $w->from('journal_lines as cl')->join('accounting_accounts as ca', 'ca.id', '=', 'cl.account_id')
                ->whereColumn('cl.journal_id', 'l.journal_id')->whereNotNull('ca.cash_kind'));
        $kept ? $this->kept($q, $ids) : $this->between($q, $ids);

        return $q->groupBy('l.account_id')->select('l.account_id')->selectRaw('SUM(l.debit) AS d, SUM(l.credit) AS c')->get();
    }

    private function cashSums(array $ids, ?string $from, ?string $to): Collection
    {
        return $this->lines($ids, $from, $to, true)->join('accounting_accounts as a', 'a.id', '=', 'l.account_id')->whereNotNull('a.cash_kind')
            ->groupBy('l.account_id')->select('l.account_id')->selectRaw('SUM(l.debit) AS d, SUM(l.credit) AS c')->get();
    }

    // ------------------------------------------------------------ helpers

    /** @return Collection<int, AccountingAccount> with their parents, by id */
    private function accounts(iterable $ids): Collection
    {
        $accounts = AccountingAccount::whereIn('id', collect($ids)->filter()->unique()->values())->get()->keyBy('id');
        $parents = $accounts->pluck('parent_id')->filter()->unique()->diff($accounts->keys());

        return $parents->isEmpty() ? $accounts : $accounts->union(AccountingAccount::whereIn('id', $parents)->get()->keyBy('id'));
    }

    /** Consolidated, a place's own account (its bank, its M-Pesa) shows under the standard header it sits in. */
    private function shownAs(AccountingAccount $a, bool $roll, Collection $accounts): AccountingAccount
    {
        return $roll && $a->territory_id && $a->parent_id && isset($accounts[$a->parent_id]) ? $accounts[$a->parent_id] : $a;
    }

    private function normal(AccountingAccount $a, $d, $c): float
    {
        $net = round((float) $d - (float) $c, 2);

        return $a->isDebitNormal() ? $net : -$net;
    }

    private function row(AccountingAccount $a, float $amount, float $lastYear): array
    {
        return ['account_id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'amount' => round($amount, 2), 'last_year' => round($lastYear, 2)];
    }

    /** By code, the made-up lines (money on its way) last; empty rows left out. */
    private function sorted(array $rows): array
    {
        $rows = array_values(array_filter($rows, fn ($r) => abs($r['amount']) >= 0.005 || abs($r['last_year'] ?? 0) >= 0.005));
        usort($rows, fn ($x, $y) => [$x['code'] === '' ? 1 : 0, $x['code']] <=> [$y['code'] === '' ? 1 : 0, $y['code']]);

        return $rows;
    }

    /** @return Collection<int, AccountingFund> General first */
    private function funds(): Collection
    {
        return AccountingFund::orderByRaw("code = 'GEN' DESC")->orderBy('display_order')->orderBy('id')->get();
    }

    private function fundInfo(AccountingFund $f): array
    {
        return ['fund_id' => $f->id, 'code' => $f->code, 'name' => $f->name, 'restricted' => (bool) $f->is_restricted];
    }

    /** A fund's own account (3100 Building fund) belongs to that fund; income and spending to the fund on the line. */
    private function fundOf(AccountingAccount $a, $lineFund, int $general): int
    {
        $this->byEquity ??= AccountingFund::whereNotNull('equity_account_id')->pluck('id', 'equity_account_id')->all();

        return $a->type === 'fund' && isset($this->byEquity[$a->id]) ? (int) $this->byEquity[$a->id] : (int) ($lineFund ?: $general);
    }

    /** Each fund's balance: its own account plus the income less spending not yet closed into it. @return array<int, float> */
    private function fundBalances(Collection $sums, Collection $accounts): array
    {
        $general = $this->funds()->first()->id;
        $out = [];
        foreach ($sums as $p) {
            $a = $accounts[$p->account_id] ?? null;
            if (! $a || ! in_array($a->type, ['fund', 'income', 'expense'], true)) {
                continue;
            }
            $fund = $this->fundOf($a, $p->fund_id ?? null, $general);
            $out[$fund] = round(($out[$fund] ?? 0) + (float) $p->c - (float) $p->d, 2);
        }

        return array_filter($out, fn ($v) => abs($v) >= 0.005);
    }

    /** @return array{0: string, 1: string} the same range a year earlier */
    private function lastYear(string $from, string $to): array
    {
        return [CarbonImmutable::parse($from)->subYear()->toDateString(), CarbonImmutable::parse($to)->subYear()->toDateString()];
    }

    private function head(Territory $place, array $ids): array
    {
        return ['place' => ['id' => $place->id, 'name' => $place->name, 'level' => $place->territory_type->value], 'consolidated' => count($ids) > 1, 'places' => count($ids)];
    }
}
