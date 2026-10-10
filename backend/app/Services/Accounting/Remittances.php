<?php

namespace App\Services\Accounting;

use App\Models\AccountingAccount;
use App\Models\BudgetDeduction;
use App\Models\BudgetLine;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\PaymentVoucher;
use App\Models\Remittance;
use App\Models\RemittanceLine;
use App\Models\Territory;
use App\Models\User;
use App\Notifications\PlaceNotification;
use App\Services\Budgets\Deductions;
use App\Support\PlaceAccess;
use App\Support\PlaceRoles;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Remittances between levels (docs/specs/accounting-spec.md, A6), on a cash
 * basis in both sets of books. What a place owes is worked out from its books
 * and the deductions set above it - never posted; sending it is a payment
 * voucher in its books (approved and paid as usual); the receiving place
 * confirms it into its own books. Nobody writes in another place's books.
 */
final class Remittances
{
    /** Money from other places is never charged a share again. */
    private const NOT_CHARGED = ['4100', '4110'];

    /** Support down is charged here unless another expense is picked. */
    private const SUPPORT_ACCOUNT = '5600';

    public function __construct(private Chart $chart, private Ledger $ledger, private Documents $docs, private Numbering $numbering, private BudgetBridge $bridge, private PaymentVouchers $vouchers, private Deductions $deductions) {}

    // ------------------------------------------------------------ what is due

    /** The deductions this place owes to another place (the rule's owner). @return Collection<int, BudgetDeduction> */
    public function rulesOwed(Territory $place): Collection
    {
        return $this->deductions->applicable($place->territory_type->value, $place->id)
            ->filter(fn (BudgetDeduction $d) => $d->territory_id && (int) $d->territory_id !== (int) $place->id && $d->budget_line_id)
            ->values();
    }

    /** The months of a year to work out: January to this month (or all twelve for a past year). @return list<string> */
    public function months(int $year): array
    {
        $last = $year < (int) now()->year ? 12 : ($year > (int) now()->year ? 0 : (int) now()->month);

        return array_map(fn ($m) => sprintf('%04d-%02d', $year, $m), $last ? range(1, $last) : []);
    }

    /** The accounts a rule is a % of: its lines' accounts, or all income but money from other places. @return list<int> */
    public function basisAccounts(BudgetDeduction $d): array
    {
        if ($d->basis === 'lines' && $d->basis_line_ids) {
            return BudgetLine::withTrashed()->whereIn('id', array_map('intval', $d->basis_line_ids))->get()
                ->map(fn ($l) => $this->chart->forBudgetLine($l)->id)->unique()->values()->all();
        }

        return AccountingAccount::where('type', 'income')->whereNotIn('code', self::NOT_CHARGED)->pluck('id')->all();
    }

    /**
     * Money received on these accounts, per place and month: credits less
     * debits on every journal (a reversal cancels what it reverses).
     *
     * @param  list<int>  $placeIds
     * @param  list<int>  $accountIds
     * @return array<int, array<int, array<string, float>>> place => account => month => amount
     */
    public function received(array $placeIds, array $accountIds, int $year): array
    {
        if (! $placeIds || ! $accountIds) {
            return [];
        }
        $rows = JournalLine::query()
            ->whereIn('territory_id', $placeIds)->whereIn('account_id', $accountIds)->whereYear('date', $year)
            ->selectRaw("territory_id, account_id, DATE_FORMAT(date, '%Y-%m') as month, SUM(credit) - SUM(debit) as net")
            ->groupBy('territory_id', 'account_id', 'month')->get();
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->territory_id][(int) $r->account_id][$r->month] = round((float) $r->net, 2);
        }

        return $out;
    }

    /**
     * What a rule comes to for a place, per month: a % of what was received,
     * or the fixed monthly amount from the month the rule (or the place's books) began.
     *
     * @param  array<int, array<string, float>>  $received  account => month => amount (this place)
     * @return array<string, float> month => due
     */
    public function dueFor(BudgetDeduction $d, array $received, int $year, ?string $booksFrom = null): array
    {
        $out = [];
        $accounts = $this->basisAccounts($d);
        $from = max($d->created_at?->format('Y-m') ?? '0000-00', $booksFrom ?? '0000-00');
        foreach ($this->months($year) as $m) {
            if ($d->deduction_type === 'percentage') {
                $base = 0;
                foreach ($accounts as $a) {
                    $base += $received[$a][$m] ?? 0;
                }
                $out[$m] = round(max($base, 0) * (float) $d->deduction_value / 100, 2);
            } else {
                $out[$m] = $m >= $from ? round((float) $d->deduction_value, 2) : 0.0;
            }
        }

        return $out;
    }

    /**
     * What a place's remittances on a rule add up to per month, once their
     * voucher is paid: sent (including in transit) and confirmed.
     *
     * @param  list<int>  $placeIds
     * @return array<int, array<int, array<string, array{sent: float, confirmed: float}>>> place => rule => month => figures
     */
    public function sentByMonth(array $placeIds, int $year, ?int $toId = null): array
    {
        if (! $placeIds) {
            return [];
        }
        $rows = RemittanceLine::query()->join('remittances', 'remittances.id', '=', 'remittance_lines.remittance_id')
            ->whereIn('remittances.from_territory_id', $placeIds)->where('remittances.kind', 'share')
            ->whereIn('remittances.status', ['sent', 'queried', 'confirmed'])->where('remittance_lines.month', 'like', "{$year}-%")
            ->when($toId, fn ($q) => $q->where('remittances.to_territory_id', $toId))
            ->selectRaw("remittances.from_territory_id as place, remittances.budget_deduction_id as rule, remittance_lines.month, SUM(remittance_lines.amount) as sent, SUM(CASE WHEN remittances.status = 'confirmed' THEN remittance_lines.amount ELSE 0 END) as confirmed")
            ->groupBy('place', 'rule', 'remittance_lines.month')->get();
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->place][(int) $r->rule][$r->month] = ['sent' => round((float) $r->sent, 2), 'confirmed' => round((float) $r->confirmed, 2)];
        }

        return $out;
    }

    /** "What we owe": each rule by month - due, sent, confirmed, owed. */
    public function owing(Territory $place, int $year): array
    {
        $rules = $this->rulesOwed($place);
        if ($rules->isEmpty()) {
            return [];
        }
        $received = $this->received([$place->id], $rules->flatMap(fn ($d) => $this->basisAccounts($d))->unique()->values()->all(), $year)[$place->id] ?? [];
        $sent = $this->sentByMonth([$place->id], $year)[$place->id] ?? [];
        $from = $this->booksFrom([$place->id])[$place->id] ?? null;

        return $rules->map(function (BudgetDeduction $d) use ($received, $sent, $year, $from) {
            $due = $this->dueFor($d, $received, $year, $from);
            $months = [];
            foreach ($due as $m => $amount) {
                $s = $sent[$d->id][$m] ?? ['sent' => 0, 'confirmed' => 0];
                $months[] = ['month' => $m, 'due' => $amount, 'sent' => $s['sent'], 'confirmed' => $s['confirmed'], 'owed' => round(max($amount - $s['sent'], 0), 2)];
            }
            $owner = Territory::find($d->territory_id);

            return [
                'id' => $d->id,
                'name' => $d->name,
                'rule' => $this->deductions->ruleText($d->deduction_type, (float) $d->deduction_value, $d->basis ?? 'all', false, $d->basis_line_ids ?? []),
                'to' => $owner ? ['id' => $owner->id, 'name' => $owner->name, 'level' => $owner->territory_type->value] : null,
                'line' => $d->budgetLine?->name,
                'months' => $months,
                'due' => round(array_sum(array_column($months, 'due')), 2),
                'sent' => round(array_sum(array_column($months, 'sent')), 2),
                'confirmed' => round(array_sum(array_column($months, 'confirmed')), 2),
                'owed' => round(array_sum(array_column($months, 'owed')), 2),
            ];
        })->values()->all();
    }

    /** The first month each place has anything in its books. @return array<int, string> */
    private function booksFrom(array $placeIds): array
    {
        return Journal::whereIn('territory_id', $placeIds)->selectRaw("territory_id, DATE_FORMAT(MIN(date), '%Y-%m') as first")->groupBy('territory_id')->pluck('first', 'territory_id')->all();
    }

    // ------------------------------------------------------------ sending

    /**
     * Send the share: a remittance waiting for its voucher - Dr the rule's
     * paid-through account on its budget line / Cr where it is paid from -
     * approved and paid like any payment.
     */
    public function sendShare(Territory $place, User $user, array $data): Remittance
    {
        $d = $this->rulesOwed($place)->firstWhere('id', (int) ($data['budget_deduction_id'] ?? 0));
        if (! $d) {
            throw ValidationException::withMessages(['budget_deduction_id' => ['Pick one of the shares this place sends.']]);
        }
        $to = Territory::findOrFail($d->territory_id);
        $year = (int) substr((string) ($data['lines'][0]['month'] ?? now()->format('Y-m')), 0, 4);
        $owing = collect($this->owing($place, $year))->firstWhere('id', $d->id);
        $byMonth = collect($owing['months'] ?? [])->keyBy('month');
        $lines = [];
        foreach ($data['lines'] ?? [] as $i => $l) {
            $m = (string) ($l['month'] ?? '');
            if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m) || $m > now()->format('Y-m')) {
                throw ValidationException::withMessages(["lines.{$i}.month" => ['Pick a month up to this one.']]);
            }
            if (substr($m, 0, 4) !== (string) $year) {
                throw ValidationException::withMessages(["lines.{$i}.month" => ['Send one year at a time.']]);
            }
            $amount = round((float) ($l['amount'] ?? 0), 2);
            if ($amount <= 0) {
                continue;
            }
            $lines[$m] = ['month' => $m, 'amount' => round(($lines[$m]['amount'] ?? 0) + $amount, 2), 'due' => $byMonth[$m]['due'] ?? null];
        }
        if (! $lines) {
            throw ValidationException::withMessages(['lines' => ['Enter what is sent for at least one month.']]);
        }
        ksort($lines);
        $account = $this->chart->forBudgetLine($d->budgetLine);
        $label = fn ($m) => CarbonImmutable::createFromFormat('Y-m-d', "{$m}-01")->format('M Y');
        $months = array_keys($lines);
        $span = count($months) === 1 ? $label($months[0]) : $label($months[0]).' to '.$label(end($months));

        return $this->send($place, $user, $to, [
            'kind' => 'share',
            'budget_deduction_id' => $d->id,
            'purpose' => "{$d->name} for {$span}",
            'lines' => array_values($lines),
            'pay_from_account_id' => (int) ($data['pay_from_account_id'] ?? 0),
            'voucher_lines' => array_map(fn ($l) => ['account_id' => $account->id, 'budget_line_id' => $d->budget_line_id, 'amount' => $l['amount'], 'description' => "{$d->name} - ".$label($l['month']), 'for_territory_id' => $to->id], array_values($lines)),
        ]);
    }

    /** Send support down to a place below - the same voucher route. */
    public function sendSupport(Territory $place, User $user, array $data): Remittance
    {
        $to = Territory::find((int) ($data['to_territory_id'] ?? 0));
        if (! $to || ! in_array((int) $to->id, PlaceAccess::descendantIds($place), true)) {
            throw ValidationException::withMessages(['to_territory_id' => ['Pick a place below this one.']]);
        }
        $purpose = trim((string) ($data['purpose'] ?? ''));
        if ($purpose === '') {
            throw ValidationException::withMessages(['purpose' => ['Say what the support is for.']]);
        }
        $amount = $this->docs->amount($data['amount'] ?? 0, 'amount');
        $account = ! empty($data['account_id']) ? $this->docs->postable($place, (int) $data['account_id'], 'account_id') : $this->standard(self::SUPPORT_ACCOUNT);
        if ($account->type !== 'expense') {
            throw ValidationException::withMessages(['account_id' => ['Pick the expense it is charged to.']]);
        }

        return $this->send($place, $user, $to, [
            'kind' => 'support',
            'budget_deduction_id' => null,
            'purpose' => mb_substr($purpose, 0, 255),
            'lines' => [['month' => now()->format('Y-m'), 'amount' => $amount, 'due' => null]],
            'pay_from_account_id' => (int) ($data['pay_from_account_id'] ?? 0),
            'voucher_lines' => [['account_id' => $account->id, 'amount' => $amount, 'description' => "Support to {$to->name}: ".mb_substr($purpose, 0, 150), 'for_territory_id' => $to->id]],
        ]);
    }

    private function send(Territory $place, User $user, Territory $to, array $r): Remittance
    {
        $this->docs->cashAccount($place, $r['pay_from_account_id'], 'pay_from_account_id');

        return DB::transaction(function () use ($place, $user, $to, $r) {
            $rem = Remittance::create([
                'number' => $this->numbering->next($place, 'remittance', (int) now()->year),
                'from_territory_id' => $place->id,
                'to_territory_id' => $to->id,
                'kind' => $r['kind'],
                'budget_deduction_id' => $r['budget_deduction_id'],
                'purpose' => $r['purpose'],
                'amount' => round(array_sum(array_column($r['lines'], 'amount')), 2),
                'status' => 'waiting',
                'created_by' => $user->id,
            ]);
            $rem->lines()->createMany($r['lines']);
            $pv = $this->vouchers->prepare($place, $user, [
                'date' => now()->toDateString(),
                'payee_name' => $to->name,
                'pay_from_account_id' => $r['pay_from_account_id'],
                'narration' => "{$rem->number}: {$r['purpose']}",
                'purpose' => 'remittance',
                'remittance_id' => $rem->id,
                'lines' => $r['voucher_lines'],
            ]);
            $rem->update(['payment_voucher_id' => $pv->id]);

            return $rem->fresh('lines');
        });
    }

    // ------------------------------------------------------------ the voucher

    /** Its voucher was paid: the money is on its way - the receiving place is told. */
    public function voucherPaid(PaymentVoucher $pv): void
    {
        $rem = Remittance::find($pv->remittance_id);
        if (! $rem || $rem->status !== 'waiting') {
            return;
        }
        $rem->update(['status' => 'sent', 'sent_journal_id' => $pv->journal_id, 'sent_on' => $pv->paid_on, 'method' => $pv->method, 'reference' => $pv->reference]);
        \App\Models\PaybillSettlement::where('remittance_id', $rem->id)->where('status', 'prepared')->update(['status' => 'paid']);
        $this->tell($rem->to, 'accounting.receipts.create', 'KES '.number_format((float) $rem->amount, 2)." on its way from {$rem->from->name}",
            "{$rem->purpose}. Confirm it when it reaches your account.", $rem);
    }

    /** May its payment be reversed? Not once the receiving place has confirmed it. */
    public function assertPaymentReversible(PaymentVoucher $pv): void
    {
        $rem = Remittance::find($pv->remittance_id);
        if ($rem && $rem->status === 'confirmed') {
            throw ValidationException::withMessages(['voucher' => ["{$rem->to->name} has confirmed receiving it - they undo the confirmation first."]]);
        }
    }

    /** Its payment was reversed: waiting to be paid again. */
    public function voucherUnpaid(PaymentVoucher $pv): void
    {
        Remittance::whereKey($pv->remittance_id)->whereIn('status', ['sent', 'queried'])
            ->update(['status' => 'waiting', 'sent_journal_id' => null, 'sent_on' => null, 'method' => null, 'reference' => null]);
        \App\Models\PaybillSettlement::where('remittance_id', $pv->remittance_id)->where('status', 'paid')->update(['status' => 'prepared']);
    }

    /** Its voucher was cancelled: so is the remittance. */
    public function voucherCancelled(PaymentVoucher $pv): void
    {
        Remittance::whereKey($pv->remittance_id)->where('status', 'waiting')->update(['status' => 'cancelled']);
        // A paybill settlement's voucher cancelled: its netting is undone too.
        if ($settlement = \App\Models\PaybillSettlement::where('remittance_id', $pv->remittance_id)->where('status', 'prepared')->first()) {
            app(Paybill::class)->undoSettlement($settlement, null);
        }
    }

    // ------------------------------------------------------------ receiving

    /** Confirm it reached us: our receipt - Dr where it landed / Cr contributions or allocations, with who sent it. No user: paid into the diocese paybill by M-Pesa (A6b). */
    public function confirm(Remittance $rem, ?User $user, array $data): Remittance
    {
        $to = Territory::findOrFail($rem->to_territory_id);
        $into = $this->docs->cashAccount($to, (int) ($data['into_account_id'] ?? 0), 'into_account_id');
        $date = (string) ($data['received_on'] ?? now()->toDateString());
        $this->docs->notFuture($date);

        return DB::transaction(function () use ($rem, $user, $to, $into, $date) {
            $rem = Remittance::whereKey($rem->id)->lockForUpdate()->firstOrFail();
            if (! in_array($rem->status, ['sent', 'queried'], true)) {
                throw ValidationException::withMessages(['remittance' => [$rem->status === 'confirmed' ? 'It was already confirmed.' : 'It hasn\'t been sent yet.']]);
            }
            if ($rem->sent_on && strtotime($date) < strtotime($rem->sent_on->toDateString())) {
                throw ValidationException::withMessages(['received_on' => ['It can\'t arrive before it was sent ('.$rem->sent_on->format('j M').').']]);
            }
            // A share is income to the place above, support to the place below; paybill money settled clears what the diocese held for it.
            $income = $this->standard(['share' => '4100', 'support' => '4110', 'settlement' => '1310'][$rem->kind]);
            $from = Territory::find($rem->from_territory_id);
            $journal = $this->ledger->post($to, [
                'doc_type' => 'receipt',
                'date' => $date,
                'narration' => "{$rem->number}: {$rem->purpose}",
                'party_name' => $from?->name,
                'method' => $this->docs->methodFor($into),
                'reference' => $rem->reference ?: $rem->number,
                'source_type' => 'remittance',
                'source_id' => $rem->id,
            ], [
                ['account_id' => $into->id, 'debit' => (float) $rem->amount],
                ['account_id' => $income->id, 'credit' => (float) $rem->amount, 'budget_line_id' => $this->chart->budgetLineFor($to, $income->id)?->id, 'memo' => $from?->name, 'for_territory_id' => $from?->id],
            ], $user);
            $this->bridge->journalPosted($journal, $user);
            $rem->update(['status' => 'confirmed', 'into_account_id' => $into->id, 'received_on' => $date, 'received_journal_id' => $journal->id, 'confirmed_by' => $user?->id, 'confirmed_at' => now()]);
            $this->tell($rem->from, 'accounting.payments.prepare', "{$to->name} confirmed {$rem->number}", 'KES '.number_format((float) $rem->amount, 2)." - {$rem->purpose} - reached them on ".date('j M', strtotime($date)).'.', $rem);

            return $rem->fresh('lines');
        });
    }

    /** Undo a confirmation made by mistake: our receipt is reversed; it is in transit again. */
    public function unconfirm(Remittance $rem, User $user, string $reason): Remittance
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => ['Say why.']]);
        }

        return DB::transaction(function () use ($rem, $user, $reason) {
            $rem = Remittance::whereKey($rem->id)->lockForUpdate()->firstOrFail();
            if ($rem->status !== 'confirmed') {
                throw ValidationException::withMessages(['remittance' => ['Only a confirmed remittance can be undone.']]);
            }
            $journal = Journal::findOrFail($rem->received_journal_id);
            $this->ledger->reverse($journal, $user, $reason, max(now()->toDateString(), $journal->date->toDateString()));
            $this->bridge->journalReversed($journal, $user);
            $rem->update(['status' => 'sent', 'into_account_id' => null, 'received_on' => null, 'received_journal_id' => null, 'confirmed_by' => null, 'confirmed_at' => null]);

            return $rem->fresh('lines');
        });
    }

    /** "It isn't on our statement": the sender is asked. */
    public function query(Remittance $rem, User $user, string $reason): Remittance
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => ['Say what is wrong.']]);
        }
        if ($rem->status !== 'sent') {
            throw ValidationException::withMessages(['remittance' => ['Only money in transit can be queried.']]);
        }
        $rem->update(['status' => 'queried', 'query_reason' => mb_substr($reason, 0, 255), 'queried_by' => $user->id, 'queried_at' => now(), 'answer' => null]);
        $this->tell($rem->from, 'accounting.payments.prepare', "{$rem->to->name} queried {$rem->number}", $reason, $rem);

        return $rem->fresh('lines');
    }

    /** The sender answers the query: in transit again. */
    public function answer(Remittance $rem, User $user, string $answer): Remittance
    {
        $answer = trim($answer);
        if ($answer === '') {
            throw ValidationException::withMessages(['answer' => ['Write your answer.']]);
        }
        if ($rem->status !== 'queried') {
            throw ValidationException::withMessages(['remittance' => ['It isn\'t queried.']]);
        }
        $rem->update(['status' => 'sent', 'answer' => mb_substr($answer, 0, 255)]);
        $this->tell($rem->to, 'accounting.receipts.create', "{$rem->from->name} answered about {$rem->number}", $answer, $rem);

        return $rem->fresh('lines');
    }

    // ------------------------------------------------------------ the places below

    /**
     * The board for a region or the diocese: per place below that owes it a
     * share (or has sent it anything) this year - due, sent, confirmed, in
     * transit, owed, and late when a month before this one is still owed.
     */
    public function board(Territory $place, int $year): array
    {
        $below = Territory::whereIn('id', PlaceAccess::descendantIds($place))->where('id', '!=', $place->id)->get()->keyBy('id');
        $rules = BudgetDeduction::with('budgetLine')->where('is_active', true)->where('territory_type', $place->territory_type->value)->where('territory_id', $place->id)->whereNotNull('budget_line_id')->get();
        $owes = $below->filter(fn ($t) => $rules->contains(fn ($d) => in_array($d->applies_to_level, [$t->territory_type->value, 'all'], true)));
        $sentTo = Remittance::where('to_territory_id', $place->id)->whereIn('from_territory_id', $below->keys())->where('status', '!=', 'cancelled')
            ->whereYear('created_at', $year)->get()->groupBy('from_territory_id');
        $places = $owes->keys()->merge($sentTo->keys())->unique()->values()->all();
        $received = $this->received($places, $rules->flatMap(fn ($d) => $this->basisAccounts($d))->unique()->values()->all(), $year);
        $sent = $this->sentByMonth($places, $year, $place->id);
        $from = $this->booksFrom($places);
        $now = now()->format('Y-m');

        return collect($places)->map(function ($id) use ($below, $rules, $received, $sent, $sentTo, $from, $year, $now) {
            $t = $below[$id];
            $due = 0;
            $paid = 0;
            $confirmed = 0;
            $late = false;
            foreach ($rules->filter(fn ($d) => in_array($d->applies_to_level, [$t->territory_type->value, 'all'], true)) as $d) {
                foreach ($this->dueFor($d, $received[$id] ?? [], $year, $from[$id] ?? null) as $m => $amount) {
                    $s = $sent[$id][$d->id][$m] ?? ['sent' => 0, 'confirmed' => 0];
                    $due += $amount;
                    $paid += $s['sent'];
                    $confirmed += $s['confirmed'];
                    $late = $late || ($m < $now && $amount - $s['sent'] > 0.009);
                }
            }
            $mine = $sentTo[$id] ?? collect();

            return [
                'place' => ['id' => $t->id, 'name' => $t->name, 'code' => $t->code, 'level' => $t->territory_type->value],
                'due' => round($due, 2),
                'sent' => round($paid, 2),
                'confirmed' => round($confirmed, 2),
                'in_transit' => round((float) $mine->whereIn('status', ['sent', 'queried'])->sum('amount'), 2),
                'queried' => $mine->where('status', 'queried')->count(),
                'owed' => round(max($due - $paid, 0), 2),
                'late' => $late,
                'last_sent' => $mine->whereIn('status', ['sent', 'queried', 'confirmed'])->max('sent_on')?->toDateString(),
            ];
        })->sortBy([['late', 'desc'], ['owed', 'desc']])->values()->all();
    }

    /** One place's statement with us for a year: per month due, sent, confirmed, owed, and the remittances. */
    public function statement(Territory $owner, Territory $place, int $year): array
    {
        $rules = BudgetDeduction::with('budgetLine')->where('territory_type', $owner->territory_type->value)->where('territory_id', $owner->id)
            ->where('is_active', true)->whereNotNull('budget_line_id')->whereIn('applies_to_level', [$place->territory_type->value, 'all'])->get();
        $received = $this->received([$place->id], $rules->flatMap(fn ($d) => $this->basisAccounts($d))->unique()->values()->all(), $year)[$place->id] ?? [];
        $sent = $this->sentByMonth([$place->id], $year, $owner->id)[$place->id] ?? [];
        $from = $this->booksFrom([$place->id])[$place->id] ?? null;
        $months = [];
        foreach ($this->months($year) as $m) {
            $months[$m] = ['month' => $m, 'due' => 0.0, 'sent' => 0.0, 'confirmed' => 0.0];
        }
        foreach ($rules as $d) {
            foreach ($this->dueFor($d, $received, $year, $from) as $m => $amount) {
                $months[$m]['due'] += $amount;
                $months[$m]['sent'] += $sent[$d->id][$m]['sent'] ?? 0;
                $months[$m]['confirmed'] += $sent[$d->id][$m]['confirmed'] ?? 0;
            }
        }
        $running = 0;
        foreach ($months as &$row) {
            $running += $row['due'] - $row['sent'];
            $row = array_map(fn ($v) => is_float($v) ? round($v, 2) : $v, $row) + ['balance' => round($running, 2)];
        }
        unset($row);

        return [
            'place' => ['id' => $place->id, 'name' => $place->name, 'code' => $place->code],
            'owner' => ['id' => $owner->id, 'name' => $owner->name],
            'year' => $year,
            'rules' => $rules->map(fn ($d) => ['id' => $d->id, 'name' => $d->name, 'rule' => $this->deductions->ruleText($d->deduction_type, (float) $d->deduction_value, $d->basis ?? 'all', false, $d->basis_line_ids ?? [])])->values(),
            'months' => array_values($months),
            'remittances' => Remittance::where('from_territory_id', $place->id)->where('to_territory_id', $owner->id)->where('status', '!=', 'cancelled')->whereYear('created_at', $year)->orderBy('id')->get()
                ->map(fn ($r) => ['id' => $r->id, 'number' => $r->number, 'purpose' => $r->purpose, 'amount' => (float) $r->amount, 'status' => $r->status, 'sent_on' => $r->sent_on?->toDateString(), 'received_on' => $r->received_on?->toDateString()])->values(),
        ];
    }

    // ------------------------------------------------------------ helpers

    private function standard(string $code): AccountingAccount
    {
        $this->chart->ensureStandard();

        return AccountingAccount::whereNull('territory_id')->where('code', $code)->firstOrFail();
    }

    /** A bell for whoever at the place holds the permission - after the change is saved. */
    private function tell(?Territory $place, string $permission, string $title, string $body, Remittance $rem): void
    {
        if (! $place) {
            return;
        }
        $notice = new PlaceNotification('remittance', $title, $body, "/{$place->territory_type->value}/accounting/remittances.php?remittance={$rem->id}", $place, 'ri-exchange-funds-line');
        DB::afterCommit(function () use ($place, $permission, $notice) {
            foreach (PlaceRoles::withPermission($place, $permission) as $u) {
                $u->notify($notice);
            }
        });
    }
}
