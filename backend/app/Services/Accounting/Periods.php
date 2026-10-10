<?php

namespace App\Services\Accounting;

use App\Models\AccountingAccount;
use App\Models\AccountingPeriod;
use App\Models\BankReconciliation;
use App\Models\CashCount;
use App\Models\JournalLine;
use App\Models\PaymentVoucher;
use App\Models\Territory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Month-end close (docs/specs/accounting-spec.md, A2). A month closes only
 * when every money account that moved or holds money was proven that month
 * (cash counted, bank and M-Pesa reconciled and signed off), nothing waits,
 * and the months before it are closed. Closing locks it: the Ledger refuses
 * postings dated in it. Only the level above reopens it, with a reason.
 */
final class Periods
{
    public function __construct(private Chart $chart, private Ledger $ledger, private PettyCash $petty) {}

    /** Each month of a year, up to this one, with its status and checklist. */
    public function year(Territory $place, int $year): array
    {
        $first = JournalLine::where('territory_id', $place->id)->min('date');
        $today = CarbonImmutable::today();
        $last = $year < $today->year ? 12 : ($year === $today->year ? $today->month : 0);
        $periods = AccountingPeriod::where('territory_id', $place->id)->where('year', $year)->get()->keyBy('month');
        $months = [];
        for ($m = 1; $m <= $last; $m++) {
            $start = CarbonImmutable::create($year, $m, 1);
            $before = ! $first || $start->endOfMonth()->toDateString() < $first;
            $p = $periods->get($m);
            $months[] = [
                'year' => $year,
                'month' => $m,
                'label' => $start->format('F Y'),
                'status' => $p?->status ?? 'open',
                'closed_at' => $p?->closed_at?->toIso8601String(),
                'closed_by' => $p?->closed_by ? User::find($p->closed_by)?->full_name : null,
                'reopened_at' => $p?->reopened_at?->toIso8601String(),
                'reopen_reason' => $p?->reopen_reason,
                'before_books' => $before,
                'ended' => $start->endOfMonth()->lt($today),
            ] + ($before ? ['checks' => [], 'blockers' => [], 'warnings' => []] : $this->checklist($place, $year, $m));
        }

        return $months;
    }

    /**
     * What stands between a month and closing it.
     *
     * @return array{checks: array, blockers: array<int, string>, warnings: array<int, string>}
     */
    public function checklist(Territory $place, int $year, int $month): array
    {
        $start = CarbonImmutable::create($year, $month, 1);
        $end = $start->endOfMonth();
        [$s, $e] = [$start->toDateString(), $end->toDateString()];
        $checks = [];
        $blockers = [];
        $warnings = [];

        foreach ($this->chart->cashAccounts($place, false) as $a) {
            $moved = JournalLine::where('territory_id', $place->id)->where('account_id', $a->id)->whereBetween('date', [$s, $e])->exists();
            $holds = abs($this->ledger->balance($place, $a, $e)) >= 0.005;
            if (! $moved && ! $holds) {
                continue;
            }
            if (in_array($a->cash_kind, ['cash', 'petty_cash'], true)) {
                $done = CashCount::where('territory_id', $place->id)->where('account_id', $a->id)->whereBetween('counted_on', [$s, $e])->whereIn('status', ['balanced', 'approved'])->orderByDesc('counted_on')->first();
                $waiting = CashCount::where('territory_id', $place->id)->where('account_id', $a->id)->whereBetween('counted_on', [$s, $e])->where('status', 'waiting')->exists();
                $checks[] = ['account_id' => $a->id, 'account' => $a->name, 'kind' => $a->cash_kind, 'what' => 'Cash counted', 'ok' => (bool) $done, 'when' => $done?->counted_on?->toDateString()];
                if (! $done) {
                    $blockers[] = $waiting ? "{$a->name}: a count with a difference is waiting for approval." : "{$a->name}: count the cash in ".$start->format('F').'.';
                }
            } else {
                $done = BankReconciliation::where('territory_id', $place->id)->where('account_id', $a->id)->where('status', 'approved')->whereBetween('statement_date', [$s, $e])->orderByDesc('statement_date')->first();
                $checks[] = ['account_id' => $a->id, 'account' => $a->name, 'kind' => $a->cash_kind, 'what' => 'Reconciled to the statement', 'ok' => (bool) $done, 'when' => $done?->statement_date?->toDateString()];
                if (! $done) {
                    $blockers[] = "{$a->name}: reconcile it to a statement dated in ".$start->format('F').' and have it signed off.';
                }
            }
        }

        if ($waiting = Collections::waitingIn($place, $s, $e)) {
            $blockers[] = "{$waiting} ".($waiting === 1 ? 'collection is' : 'collections are').' still waiting to be confirmed.';
        }
        $unbanked = Collections::unbanked($place, $e);
        if ($unbanked['count']) {
            $warnings[] = "Cash from {$unbanked['count']} ".($unbanked['count'] === 1 ? 'collection' : 'collections').' (KES '.number_format($unbanked['total'], 2).') is not banked yet.';
        }

        $unpaid = PaymentVoucher::where('territory_id', $place->id)->where('status', 'authorised')->whereBetween('date', [$s, $e])->count();
        if ($unpaid) {
            $warnings[] = "{$unpaid} authorised ".($unpaid === 1 ? 'voucher is' : 'vouchers are').' not paid yet.';
        }
        $petty = $this->petty->status($place);
        if ($petty['float'] !== null && $petty['top_up'] > 0 && $petty['vouchers_since'] > 0) {
            $warnings[] = 'Petty cash is KES '.number_format($petty['top_up'], 2).' below its float - top it up.';
        }

        // A4 - advances not accounted for by their date.
        $overdue = \App\Models\StaffAdvance::where('territory_id', $place->id)->where('status', 'open')->where('due_on', '<=', $e)->get();
        if ($overdue->isNotEmpty()) {
            $warnings[] = "{$overdue->count()} staff ".($overdue->count() === 1 ? 'advance is' : 'advances are').' past the date to account for '.($overdue->count() === 1 ? 'it' : 'them').' (KES '.number_format($overdue->sum(fn ($a) => $a->outstanding()), 2).' still out).';
        }
        // A5 - supplier bills past their due date.
        $bills = \App\Models\SupplierInvoice::where('territory_id', $place->id)->where('status', 'posted')->whereNotNull('due_on')->where('due_on', '<=', $e)->get();
        if ($bills->isNotEmpty()) {
            $warnings[] = "{$bills->count()} supplier ".($bills->count() === 1 ? 'bill is' : 'bills are').' past due (KES '.number_format((float) $bills->sum('amount'), 2).').';
        }
        // A6 - money between places.
        $out = \App\Models\Remittance::where('from_territory_id', $place->id)->whereIn('status', ['sent', 'queried'])->where('sent_on', '<=', $e)->get();
        if ($out->where('status', 'queried')->isNotEmpty()) {
            $warnings[] = $out->where('status', 'queried')->count().' sent '.($out->where('status', 'queried')->count() === 1 ? 'remittance is' : 'remittances are').' queried by the place receiving it - answer under Remittances.';
        }
        if ($out->where('status', 'sent')->isNotEmpty()) {
            $warnings[] = 'KES '.number_format((float) $out->where('status', 'sent')->sum('amount'), 2).' sent to other places is not confirmed received yet.';
        }
        $in = \App\Models\Remittance::where('to_territory_id', $place->id)->whereIn('status', ['sent', 'queried'])->where('sent_on', '<=', $e)->get();
        if ($in->isNotEmpty()) {
            $warnings[] = 'KES '.number_format((float) $in->sum('amount'), 2).' from other places is waiting for you to confirm it reached you.';
        }
        // A8 - the diocese paybill.
        if ($place->territory_type->value === 'diocese') {
            if ($toSort = \App\Models\MpesaPayment::where('status', 'to_sort')->where('paid_at', '<=', $end->endOfDay())->count()) {
                $warnings[] = "{$toSort} paybill ".($toSort === 1 ? 'payment is' : 'payments are').' still to sort - under Paybill.';
            }
            $held = $this->chart->account('held_for_others')->id;
            $paid = \App\Models\JournalLine::where('territory_id', $place->id)->where('account_id', $held)->whereNotNull('for_territory_id')->whereBetween('date', [$s, $e])->where('credit', '>', 0)->distinct()->pluck('for_territory_id');
            $settled = \App\Models\PaybillSettlement::where('month', $start->format('Y-m'))->where('status', '!=', 'cancelled')->pluck('territory_id');
            if (($left = $paid->diff($settled)->count()) && $start->format('Y-m') < now()->format('Y-m')) {
                $warnings[] = "{$left} ".($left === 1 ? 'place\'s' : 'places\'').' paybill money for '.$start->format('F').' is not settled yet.';
            }
        }
        // A10c - card money: a failed payout, a gift still disputed, a part refund not adjusted.
        if ($failed = \App\Models\PaystackSettlement::where('territory_id', $place->id)->where('status', 'failed')->whereBetween('settled_on', [$s, $e])->count()) {
            $warnings[] = "{$failed} Paystack ".($failed === 1 ? 'payout' : 'payouts').' failed - check the bank details under Online giving, Getting paid.';
        }
        $gifts = \App\Models\Gift::where('territory_id', $place->id)->where('method', 'paystack')->where('status', 'paid')->where('paid_at', '<=', $end->endOfDay());
        if ($disputed = (clone $gifts)->whereNotNull('disputed_at')->count()) {
            $warnings[] = "{$disputed} card ".($disputed === 1 ? 'gift is' : 'gifts are').' disputed by the giver\'s bank - see Online giving.';
        }
        if ($part = (clone $gifts)->where('refunded_amount', '>', 0)->count()) {
            $warnings[] = "{$part} card ".($part === 1 ? 'gift was' : 'gifts were').' part refunded - adjust '.($part === 1 ? 'it' : 'them').' with a journal.';
        }
        $owed = collect(app(Remittances::class)->owing($place, $year))->sum(fn ($r) => collect($r['months'])->firstWhere('month', $start->format('Y-m'))['owed'] ?? 0);
        if ($owed > 0.009) {
            $warnings[] = 'KES '.number_format($owed, 2).' of the '.$start->format('F').' share is not sent yet.';
        }

        return ['checks' => $checks, 'blockers' => $blockers, 'warnings' => $warnings];
    }

    public function close(Territory $place, User $user, int $year, int $month): AccountingPeriod
    {
        $start = CarbonImmutable::create($year, $month, 1);
        if (! $start->endOfMonth()->lt(CarbonImmutable::today())) {
            throw ValidationException::withMessages(['month' => [$start->format('F Y').' hasn\'t ended yet.']]);
        }
        $first = JournalLine::where('territory_id', $place->id)->min('date');
        if ($first) {
            $earlier = $this->openMonthsBefore($place, CarbonImmutable::parse($first)->startOfMonth(), $start);
            if ($earlier) {
                throw ValidationException::withMessages(['month' => ['Close '.$earlier[0].' first - months close in order.']]);
            }
        }
        $c = $this->checklist($place, $year, $month);
        if ($c['blockers']) {
            throw ValidationException::withMessages(['month' => $c['blockers']]);
        }

        return AccountingPeriod::updateOrCreate(
            ['territory_id' => $place->id, 'year' => $year, 'month' => $month],
            ['status' => 'closed', 'closed_by' => $user->id, 'closed_at' => now()],
        );
    }

    public function reopen(Territory $place, User $user, int $year, int $month, string $reason): AccountingPeriod
    {
        $p = AccountingPeriod::where('territory_id', $place->id)->where('year', $year)->where('month', $month)->first();
        if (! $p || $p->status !== 'closed') {
            throw ValidationException::withMessages(['month' => ['That month isn\'t closed.']]);
        }
        if (\App\Models\AccountingYear::where('territory_id', $place->id)->where('year', $year)->where('status', 'closed')->exists()) {
            throw ValidationException::withMessages(['month' => ["{$year} is closed - reopen the year first."]]);
        }
        $later = AccountingPeriod::where('territory_id', $place->id)->where('status', 'closed')
            ->where(fn ($q) => $q->where('year', '>', $year)->orWhere(fn ($w) => $w->where('year', $year)->where('month', '>', $month)))->exists();
        if ($later) {
            throw ValidationException::withMessages(['month' => ['Reopen the later closed months first - the latest one comes first.']]);
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => ['Say why it is being reopened.']]);
        }
        $p->update(['status' => 'open', 'reopened_by' => $user->id, 'reopened_at' => now(), 'reopen_reason' => mb_substr($reason, 0, 255)]);

        return $p->fresh();
    }

    /** The last closed month of each place (territory_id => 'YYYY-MM'). */
    public function lastClosed(array $placeIds): array
    {
        return AccountingPeriod::whereIn('territory_id', $placeIds)->where('status', 'closed')
            ->select('territory_id', DB::raw("MAX(CONCAT(year, '-', LPAD(month, 2, '0'))) AS ym"))->groupBy('territory_id')->pluck('ym', 'territory_id')->all();
    }

    /** Open months from the first with postings up to (not including) $before, as labels. */
    private function openMonthsBefore(Territory $place, CarbonImmutable $from, CarbonImmutable $before): array
    {
        $closed = AccountingPeriod::where('territory_id', $place->id)->where('status', 'closed')->get()->map(fn ($p) => sprintf('%04d-%02d', $p->year, $p->month))->flip();
        $out = [];
        for ($m = $from; $m->lt($before); $m = $m->addMonth()) {
            if (! $closed->has($m->format('Y-m'))) {
                $out[] = $m->format('F Y');
            }
        }

        return $out;
    }

    /** Cash is counted; a bank or M-Pesa account is reconciled to its statement. */
    public static function isCounted(AccountingAccount $a): bool
    {
        return in_array($a->cash_kind, ['cash', 'petty_cash'], true);
    }
}
