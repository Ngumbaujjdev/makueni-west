<?php

namespace App\Services\Accounting;

use App\Models\AccountingAccount;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\PaymentVoucher;
use App\Models\Territory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reading a place's books (docs/specs/accounting-spec.md): the cash
 * position, the cashbook of one account with its running balance, the
 * documents, and how each one is shown. The same for every level.
 */
final class Books
{
    public function __construct(private Chart $chart, private Ledger $ledger) {}

    /** Each money account with its balance today. */
    public function cashPosition(Territory $place): array
    {
        $accounts = $this->chart->cashAccounts($place, false);
        $balances = $this->ledger->balances($place, $accounts);
        $used = JournalLine::where('territory_id', $place->id)->whereIn('account_id', $accounts->pluck('id'))->distinct()->pluck('account_id')
            // Petty cash with a float set shows even before its first spend.
            ->merge(\App\Models\AccountingPlaceAccount::where('territory_id', $place->id)->whereNotNull('imprest_float')->pluck('account_id'))->flip();

        return $accounts
            // A standard account the place never used and a switched-off one with nothing in it stay out of the way.
            ->filter(fn ($a) => $a->territory_id !== null ? ($a->is_active || abs($balances[$a->id]) >= 0.005) : ($a->system_key === 'cash_at_hand' || $used->has($a->id)))
            ->map(fn ($a) => $this->presentAccount($a) + ['balance' => $balances[$a->id]])
            ->values()->all();
    }

    /**
     * The cashbook of one money account between two dates: the balance
     * brought forward, every movement with the running balance, and the
     * balance carried forward.
     */
    public function cashbook(Territory $place, AccountingAccount $account, string $from, string $to): array
    {
        $opening = $this->ledger->balance($place, $account, null, $from);
        $lines = JournalLine::with('journal')
            ->where('territory_id', $place->id)->where('account_id', $account->id)
            ->whereBetween('date', [$from, $to])
            ->orderBy('date')->orderBy('journal_id')->orderBy('line_no')->get();
        // What the other side of each journal was (Tithes, Cash at hand...).
        $others = JournalLine::with('account:id,code,name')->whereIn('journal_id', $lines->pluck('journal_id')->unique())
            ->where('account_id', '!=', $account->id)->get()->groupBy('journal_id');
        $running = $opening;
        $rows = [];
        $in = $out = 0;
        foreach ($lines as $l) {
            $running = round($running + (float) $l->debit - (float) $l->credit, 2);
            $in += (float) $l->debit;
            $out += (float) $l->credit;
            $j = $l->journal;
            $rows[] = [
                'id' => $l->id,
                'journal_id' => $j->id,
                'date' => $l->date->toDateString(),
                'number' => $j->number,
                'doc_type' => $j->doc_type,
                'type_label' => Journal::TYPES[$j->doc_type],
                'party' => $j->party_name,
                'details' => $j->narration,
                'against' => ($others[$j->id] ?? collect())->map(fn ($o) => $o->account?->name)->unique()->values()->all(),
                'method' => $j->method,
                'reference' => $j->reference,
                'in' => (float) $l->debit,
                'out' => (float) $l->credit,
                'balance' => $running,
                'reversed' => $j->status === 'reversed',
            ];
        }

        return [
            'account' => $this->presentAccount($account),
            'from' => $from,
            'to' => $to,
            'opening' => $opening,
            'in' => round($in, 2),
            'out' => round($out, 2),
            'closing' => $running,
            'rows' => $rows,
        ];
    }

    /**
     * One account's page (Redesign R2): its balance, what went in and out
     * this month, last month and this year, twelve months of in and out, the
     * latest movements, its last reconciliation and the budget lines that
     * post to it (planned and actual from the budget in use - read only).
     * "In" is whatever makes the account bigger on its normal side.
     */
    public function accountPage(Territory $place, AccountingAccount $a): array
    {
        $debitNormal = $a->isDebitNormal();
        $today = CarbonImmutable::today();
        $start = $today->startOfMonth()->subMonths(11);
        $rows = DB::table('journal_lines')->where('territory_id', $place->id)->where('account_id', $a->id)->where('date', '>=', $start->toDateString())
            ->groupByRaw("DATE_FORMAT(date, '%Y-%m')")
            ->selectRaw("DATE_FORMAT(date, '%Y-%m') AS ym, COALESCE(SUM(debit), 0) AS d, COALESCE(SUM(credit), 0) AS c")
            ->get()->keyBy('ym');
        $series = ['labels' => [], 'months' => [], 'in' => [], 'out' => []];
        for ($m = $start; $m->lte($today); $m = $m->addMonth()) {
            $k = $m->format('Y-m');
            $d = (float) ($rows[$k]->d ?? 0);
            $c = (float) ($rows[$k]->c ?? 0);
            $series['labels'][] = $m->format('M Y');
            $series['months'][] = $k;
            $series['in'][] = round($debitNormal ? $d : $c, 2);
            $series['out'][] = round($debitNormal ? $c : $d, 2);
        }
        $sum = fn (callable $keep) => ['in' => round(array_sum(array_filter($series['in'], $keep, ARRAY_FILTER_USE_KEY)), 2), 'out' => round(array_sum(array_filter($series['out'], $keep, ARRAY_FILTER_USE_KEY)), 2)];
        $idx = array_flip($series['months']);
        $thisMonth = $idx[$today->format('Y-m')];
        $lastMonth = $idx[$today->subMonth()->format('Y-m')] ?? -1;
        $yearFrom = $idx[$today->format('Y').'-01'] ?? 0;

        $lines = JournalLine::with('journal.media')->where('territory_id', $place->id)->where('account_id', $a->id)
            ->orderByDesc('date')->orderByDesc('journal_id')->orderByDesc('line_no')->limit(15)->get();
        $others = JournalLine::with('account:id,code,name,cash_kind')->whereIn('journal_id', $lines->pluck('journal_id')->unique())
            ->where('account_id', '!=', $a->id)->get()->groupBy('journal_id');
        // A statement: newest first, each with the balance right after it, worked back from today's.
        $running = $this->ledger->balance($place, $a);
        $movements = [];
        foreach ($lines as $l) {
            $in = round($debitNormal ? (float) $l->debit : (float) $l->credit, 2);
            $out = round($debitNormal ? (float) $l->credit : (float) $l->debit, 2);
            $j = $l->journal;
            $movements[] = [
                'journal_id' => $l->journal_id,
                'date' => $l->date->toDateString(),
                'number' => $j->number,
                'doc_type' => $j->doc_type,
                'party' => $j->party_name,
                'details' => $j->narration,
                'method' => $j->method,
                'method_label' => $j->method ? (Journal::METHODS[$j->method] ?? $j->method) : null,
                'source' => $j->source_type,
                'source_id' => $j->source_id,
                'status' => $j->status,
                'attachments' => $j->getMedia('attachments')->count(),
                'against' => ($others[$l->journal_id] ?? collect())->map(fn ($o) => ['name' => $o->account?->name, 'cash_kind' => $o->account?->cash_kind])->unique('name')->values()->all(),
                'direction' => $in > 0 ? 'in' : 'out',
                'in' => $in,
                'out' => $out,
                'balance_after' => round($running, 2),
                'reversed' => $j->status === 'reversed',
            ];
            $running -= $in - $out;
        }
        $yearCount = JournalLine::where('territory_id', $place->id)->where('account_id', $a->id)->where('date', '>=', $today->startOfYear()->toDateString())->count();

        $rec = $a->cash_kind ? \App\Models\BankReconciliation::where('territory_id', $place->id)->where('account_id', $a->id)->orderByDesc('statement_date')->orderByDesc('id')->first() : null;
        $budget = app(\App\Services\Budgets\BudgetBook::class)->budgetInUseOn($place->territory_type->value, $place->id, $today->toDateString());
        // The budget lines that post to it, and - when a budget is in use today - what each planned and spent.
        $items = $budget ? $budget->budgetLineItems()->get()->keyBy('budget_line_id') : collect();
        $budgetLines = \App\Models\BudgetLine::where('account_id', $a->id)->where(fn ($q) => $q->whereNull('territory_id')->orWhere('territory_id', $place->id))
            ->orderBy('name')->get()
            ->map(fn ($l) => ['line_id' => $l->id, 'name' => $l->name, 'planned' => isset($items[$l->id]) ? (float) $items[$l->id]->budgeted_amount : null, 'actual' => isset($items[$l->id]) ? (float) $items[$l->id]->actual_amount : null])->values()->all();
        $labels = match ($a->type) {
            'expense' => ['Spent', 'Taken back'],
            'income' => ['Received', 'Given back'],
            'liability', 'fund' => ['Added', 'Used'],
            default => ['Money in', 'Money out'],
        };

        return [
            'account' => $this->presentAccount($a) + ['type' => $a->type, 'own' => (int) $a->territory_id === (int) $place->id],
            'balance' => $this->ledger->balance($place, $a),
            'labels' => ['in' => $labels[0], 'out' => $labels[1]],
            'this_month' => $sum(fn ($k) => $k === $thisMonth),
            'last_month' => $sum(fn ($k) => $k === $lastMonth),
            'this_year' => $sum(fn ($k) => $k >= $yearFrom),
            'series' => $series,
            'movements' => $movements,
            'year_count' => $yearCount,
            'reconciled' => $rec ? ['id' => $rec->id, 'date' => $rec->statement_date?->toDateString(), 'status' => $rec->status] : null,
            'budget' => $budget ? ['id' => $budget->id, 'name' => $budget->name] : null,
            'budget_lines' => $budgetLines,
        ];
    }

    /**
     * Money received by channel (Redesign R2b): cash, M-Pesa, Airtel Money,
     * bank or card - from receipts' lines that put money into a money account,
     * so a Sunday collection splits into its cash and its M-Pesa. Card is the
     * journal's method (Paystack lands in the clearing account first). Six
     * months, this one last; reversed receipts left out.
     */
    public function channels(Territory $place, int $months = 6): array
    {
        $start = CarbonImmutable::today()->startOfMonth()->subMonths($months - 1);
        $yearStart = CarbonImmutable::today()->startOfYear();
        $from = $start->lt($yearStart) ? $start : $yearStart;
        $rows = DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')->join('accounting_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.territory_id', $place->id)->where('j.doc_type', 'receipt')->where('j.status', '!=', 'reversed')
            ->where('a.type', 'asset')->where('l.debit', '>', 0)->where('l.date', '>=', $from->toDateString())
            ->where(fn ($q) => $q->whereNotNull('a.cash_kind')->orWhere('j.method', 'card'))
            ->groupByRaw("DATE_FORMAT(l.date, '%Y-%m'), channel")
            ->selectRaw("DATE_FORMAT(l.date, '%Y-%m') AS ym, CASE WHEN j.method = 'card' THEN 'card' WHEN a.cash_kind IN ('cash', 'petty_cash') THEN 'cash' ELSE a.cash_kind END AS channel, SUM(l.debit) AS total")
            ->get();
        $kinds = AccountingAccount::usableBy($place->id)->whereNotNull('cash_kind')->where('is_header', false)->where('is_active', true)->pluck('cash_kind')
            ->map(fn ($k) => $k === 'petty_cash' ? 'cash' : $k)->unique()->all();
        $labels = ['cash' => 'Cash', 'mpesa' => 'M-Pesa', 'airtel' => 'Airtel Money', 'bank' => 'Bank', 'card' => 'Card (online)'];
        $monthKeys = [];
        for ($m = $start; $m->lte(CarbonImmutable::today()); $m = $m->addMonth()) {
            $monthKeys[] = $m->format('Y-m');
        }
        $thisMonth = end($monthKeys);
        $lastMonth = CarbonImmutable::today()->subMonthNoOverflow()->format('Y-m');
        $out = [];
        foreach ($labels as $key => $label) {
            $mine = $rows->where('channel', $key);
            $year = round((float) $mine->filter(fn ($r) => $r->ym >= $yearStart->format('Y-m'))->sum('total'), 2);
            if (! $year && ! in_array($key, $kinds, true)) {
                continue;
            }
            $by = $mine->keyBy('ym');
            $out[] = [
                'key' => $key,
                'label' => $label,
                'this_month' => round((float) ($by[$thisMonth]->total ?? 0), 2),
                'last_month' => round((float) ($by[$lastMonth]->total ?? 0), 2),
                'year' => $year,
                'series' => array_map(fn ($k) => round((float) ($by[$k]->total ?? 0), 2), $monthKeys),
            ];
        }

        return ['labels' => array_map(fn ($k) => CarbonImmutable::parse("{$k}-01")->format('M Y'), $monthKeys), 'items' => $out];
    }

    /** Money in and out for a period: income and expense accounts, transfers left out. */
    public function inOut(Territory $place, string $from, string $to): array
    {
        $row = DB::table('journal_lines as l')->join('accounting_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.territory_id', $place->id)->whereBetween('l.date', [$from, $to])
            ->selectRaw("COALESCE(SUM(CASE WHEN a.type = 'income' THEN l.credit - l.debit END), 0) AS income, COALESCE(SUM(CASE WHEN a.type = 'expense' THEN l.debit - l.credit END), 0) AS expense")
            ->first();

        return ['in' => round((float) $row->income, 2), 'out' => round((float) $row->expense, 2)];
    }

    /** The last N months' money in and out, oldest first (for the sparklines). */
    public function monthly(Territory $place, int $months = 12): array
    {
        $start = CarbonImmutable::today()->startOfMonth()->subMonths($months - 1);
        $rows = DB::table('journal_lines as l')->join('accounting_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.territory_id', $place->id)->where('l.date', '>=', $start->toDateString())
            ->groupByRaw("DATE_FORMAT(l.date, '%Y-%m')")
            ->selectRaw("DATE_FORMAT(l.date, '%Y-%m') AS ym, COALESCE(SUM(CASE WHEN a.type = 'income' THEN l.credit - l.debit END), 0) AS income, COALESCE(SUM(CASE WHEN a.type = 'expense' THEN l.debit - l.credit END), 0) AS expense")
            ->get()->keyBy('ym');
        $out = ['labels' => [], 'in' => [], 'out' => []];
        for ($m = $start; $m->lte(CarbonImmutable::today()); $m = $m->addMonth()) {
            $k = $m->format('Y-m');
            $out['labels'][] = $m->format('M Y');
            $out['in'][] = round((float) ($rows[$k]->income ?? 0), 2);
            $out['out'][] = round((float) ($rows[$k]->expense ?? 0), 2);
        }

        return $out;
    }

    /** Money received this period, by fund. */
    public function byFund(Territory $place, string $from, string $to): array
    {
        $rows = DB::table('journal_lines as l')->join('accounting_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.territory_id', $place->id)->whereBetween('l.date', [$from, $to])->whereIn('a.type', ['income', 'expense'])
            ->groupBy('l.fund_id')
            ->selectRaw("l.fund_id, COALESCE(SUM(CASE WHEN a.type = 'income' THEN l.credit - l.debit END), 0) AS income, COALESCE(SUM(CASE WHEN a.type = 'expense' THEN l.debit - l.credit END), 0) AS expense")
            ->get()->keyBy('fund_id');

        return app(Funds::class)->forPlace($place)->map(fn ($f) => [
            'id' => $f->id, 'code' => $f->code, 'name' => $f->name, 'restricted' => $f->is_restricted,
            'in' => round((float) ($rows[$f->id]->income ?? 0), 2), 'out' => round((float) ($rows[$f->id]->expense ?? 0), 2),
        ])->all();
    }

    /** Income or expenses by account for a period, biggest first. */
    public function byAccount(Territory $place, string $type, string $from, string $to, int $limit = 6): array
    {
        $sign = $type === 'income' ? 'l.credit - l.debit' : 'l.debit - l.credit';

        return DB::table('journal_lines as l')->join('accounting_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.territory_id', $place->id)->whereBetween('l.date', [$from, $to])->where('a.type', $type)
            ->groupBy('a.id', 'a.code', 'a.name')
            ->selectRaw("a.id, a.code, a.name, SUM({$sign}) AS total")
            ->havingRaw("SUM({$sign}) <> 0")
            ->orderByDesc('total')->limit($limit)->get()
            ->map(fn ($r) => ['id' => $r->id, 'code' => $r->code, 'name' => $r->name, 'total' => round((float) $r->total, 2)])->all();
    }

    public function presentAccount(AccountingAccount $a): array
    {
        return [
            'id' => $a->id,
            'code' => $a->code,
            'name' => $a->name,
            'description' => $a->description,
            'type' => $a->type,
            'type_label' => AccountingAccount::TYPES[$a->type],
            'cash_kind' => $a->cash_kind,
            'kind_label' => $a->cash_kind ? AccountingAccount::CASH_KINDS[$a->cash_kind] : null,
            'own' => $a->territory_id !== null,
            'parent_id' => $a->parent_id,
            'is_header' => $a->is_header,
            'is_active' => $a->is_active,
            'system_key' => $a->system_key,
            'bank_name' => $a->bank_name,
            'branch' => $a->branch,
            'number_masked' => $a->maskedNumber(),
            'account_number' => $a->account_number,
            'mpesa_number' => $a->mpesa_number,
        ];
    }

    public function presentJournal(Journal $j, bool $full = false): array
    {
        $out = [
            'id' => $j->id,
            'number' => $j->number,
            'doc_type' => $j->doc_type,
            'type_label' => Journal::TYPES[$j->doc_type],
            'date' => $j->date->toDateString(),
            'narration' => $j->narration,
            'party_name' => $j->party_name,
            'party_phone' => $j->party_phone,
            'method' => $j->method,
            'method_label' => $j->method ? Journal::METHODS[$j->method] : null,
            'reference' => $j->reference,
            'amount' => (float) $j->amount,
            'status' => $j->status,
            'source' => $j->source_type,
            'source_id' => $j->source_id,
            'reverses_id' => $j->reverses_id,
            'reversed_by_id' => $j->reversed_by_id,
            'reverse_reason' => $j->reverse_reason,
            'posted_by' => $j->poster?->full_name,
            'posted_at' => $j->posted_at?->toIso8601String(),
            'attachments' => $j->relationLoaded('media') ? $j->media->count() : $j->getMedia('attachments')->count(),
        ];
        if ($full) {
            $out['lines'] = $j->lines()->with(['account:id,code,name,type,cash_kind', 'fund:id,code,name', 'budgetLine:id,name'])->get()->map(fn ($l) => [
                'id' => $l->id,
                'account' => ['id' => $l->account->id, 'code' => $l->account->code, 'name' => $l->account->name, 'type' => $l->account->type, 'cash' => (bool) $l->account->cash_kind],
                'fund' => $l->fund ? ['id' => $l->fund->id, 'code' => $l->fund->code, 'name' => $l->fund->name] : null,
                'budget_line' => $l->budgetLine?->name,
                'debit' => (float) $l->debit,
                'credit' => (float) $l->credit,
                'memo' => $l->memo,
            ])->all();
            $out['files'] = $j->getMedia('attachments')->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'mime' => $m->mime_type, 'size' => $m->size])->values()->all();
            $out['reverses'] = $j->reverses ? ['id' => $j->reverses->id, 'number' => $j->reverses->number] : null;
            $out['reversed_by'] = $j->reversedBy ? ['id' => $j->reversedBy->id, 'number' => $j->reversedBy->number, 'date' => $j->reversedBy->date->toDateString()] : null;
            $out['voucher'] = $j->source_type === 'payment_voucher' ? PaymentVoucher::whereKey($j->source_id)->value('number') : null;
            $out['budget_entry_ids'] = DB::table('budget_entries')->where('journal_id', $j->id)->whereNull('deleted_at')->pluck('id')->all();
        }

        return $out;
    }

    public function presentVoucher(PaymentVoucher $pv, bool $full = false): array
    {
        $name = fn (?User $u) => $u?->full_name;
        $out = [
            'id' => $pv->id,
            'number' => $pv->number,
            'date' => $pv->date->toDateString(),
            'payee_name' => $pv->payee_name,
            'payee_phone' => $pv->payee_phone,
            'payee' => $pv->payee, 'payee_text' => \App\Support\PayTo::describe($pv->payee),
            'narration' => $pv->narration,
            'amount' => (float) $pv->amount,
            'status' => $pv->status,
            'status_label' => PaymentVoucher::STATUSES[$pv->status],
            'purpose' => $pv->purpose,
            'pay_from' => $pv->payFrom ? ['id' => $pv->payFrom->id, 'name' => $pv->payFrom->name, 'kind' => $pv->payFrom->cash_kind] : null,
            'method' => $pv->method,
            'reference' => $pv->reference,
            'prepared_by' => $name($pv->preparer),
            'prepared_by_id' => $pv->prepared_by,
            'prepared_at' => $pv->prepared_at?->toIso8601String(),
            'authorised_by' => $name($pv->authoriser),
            'authorised_at' => $pv->authorised_at?->toIso8601String(),
            'authorise_note' => $pv->authorise_note,
            'rejected_by' => $name($pv->rejecter),
            'rejected_at' => $pv->rejected_at?->toIso8601String(),
            'reject_reason' => $pv->reject_reason,
            'paid_by' => $name($pv->payer),
            'paid_on' => $pv->paid_on?->toDateString(),
            'journal_id' => $pv->journal_id,
            'journal_number' => $pv->journal?->number,
            'attachments' => $pv->getMedia('attachments')->count(),
            'lines_count' => $pv->lines->count(),
            'charged_to' => $pv->lines->map(fn ($l) => $l->account?->name)->filter()->unique()->values()->all(),
        ];
        if ($full) {
            $out['lines'] = $pv->lines()->with(['account:id,code,name,type', 'fund:id,code,name', 'budgetLine:id,name'])->get()->map(fn ($l) => [
                'id' => $l->id,
                'account' => ['id' => $l->account->id, 'code' => $l->account->code, 'name' => $l->account->name, 'type' => $l->account->type],
                'fund' => $l->fund ? ['id' => $l->fund->id, 'code' => $l->fund->code, 'name' => $l->fund->name] : null,
                'budget_line_id' => $l->budget_line_id,
                'budget_line' => $l->budgetLine?->name,
                'description' => $l->description,
                'amount' => (float) $l->amount,
            ])->all();
            $out['files'] = $pv->getMedia('attachments')->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'mime' => $m->mime_type, 'size' => $m->size])->values()->all();
        }

        return $out;
    }

    /** The chart for a place: standard accounts and its own, with balances, grouped by type. */
    public function chartFor(Territory $place): Collection
    {
        $this->chart->ensureStandard();
        $accounts = AccountingAccount::usableBy($place->id)->orderBy('code')->get();
        $balances = $this->ledger->balances($place, $accounts->where('is_header', false));

        return $accounts->map(fn ($a) => $this->presentAccount($a) + ['balance' => $a->is_header ? null : ($balances[$a->id] ?? 0)]);
    }
}
