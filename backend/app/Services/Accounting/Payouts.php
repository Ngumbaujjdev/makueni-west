<?php

namespace App\Services\Accounting;

use App\Models\AccountingAccount;
use App\Models\Gift;
use App\Models\Journal;
use App\Models\PaymentChannel;
use App\Models\PaystackSettlement;
use App\Models\Remittance;
use App\Models\Territory;
use App\Models\User;
use App\Services\Payments\Paystack;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Getting paid (docs/specs/accounting-spec.md, A10c) - borrowed from
 * v1-events' "Getting paid" and Settlements, but kept in the books:
 * - a place asks for its own Paystack and the diocese checks it;
 * - every Paystack payout is recorded once with its status, and the gifts it
 *   paid are matched by day (Paystack doesn't list a subaccount's payments);
 * - a refund or a lost dispute undoes the gift in both books.
 */
final class Payouts
{
    /** Paystack pays out in Lagos days. */
    private const TZ = 'Africa/Lagos';

    public function __construct(private Paybill $paybill, private Ledger $ledger, private Chart $chart, private BudgetBridge $bridge) {}

    // ------------------------------------------------------------ getting paid

    public function channel(Territory $place): ?PaymentChannel
    {
        return PaymentChannel::where('territory_id', $place->id)->where('provider', 'paystack')->first();
    }

    /** How a place gets its money: card gifts and M-Pesa. */
    public function route(Territory $place): array
    {
        $ch = $this->channel($place);
        $own = $ch && $ch->isActive() && $ch->subaccount_code;
        $mpesa = $this->paybill->mpesaChannel($place);
        $into = $ch?->settles_into_id ? AccountingAccount::find($ch->settles_into_id) : null;

        return [
            'card' => [
                'open' => Paystack::ready(),
                'route' => $place->territory_type->value === 'diocese' ? 'main' : ($own ? 'own' : 'diocese'),
                'bank' => $ch?->bank_name, 'account' => $ch?->account_number ? '•••• '.substr($ch->account_number, -4) : null, 'account_name' => $ch?->account_name,
                'into' => $into ? ['id' => $into->id, 'name' => $into->name] : null,
                'status' => $ch?->status, 'subaccount' => (bool) $ch?->subaccount_code,
            ],
            'mpesa' => ['route' => $mpesa ? $mpesa->provider : 'paybill', 'number' => $mpesa?->account_number, 'till' => $mpesa?->account_name === 'Till'],
            'request' => $ch?->request ? $this->presentRequest($ch, false) : null,
            'sent_back' => $ch && ! $ch->request && $ch->review_note ? ['note' => $ch->review_note, 'on' => $ch->checked_at?->toIso8601String()] : null,
        ];
    }

    public function presentRequest(PaymentChannel $ch, bool $full): array
    {
        $r = (array) $ch->request;
        $into = isset($r['settles_into_id']) ? AccountingAccount::find($r['settles_into_id']) : null;

        return [
            'bank' => $r['bank_name'] ?? null, 'bank_code' => $r['bank_code'] ?? null,
            'account_number' => $full ? ($r['account_number'] ?? null) : (isset($r['account_number']) ? '•••• '.substr($r['account_number'], -4) : null),
            'account_name' => $r['account_name'] ?? null, 'into' => $into ? ['id' => $into->id, 'name' => $into->name] : null,
            'change' => (bool) $ch->subaccount_code, 'by' => User::find($ch->requested_by)?->name, 'on' => $ch->requested_at?->toIso8601String(),
        ];
    }

    /** A place asks for its own Paystack (or a change to it); it waits for the diocese's check. */
    public function ask(Territory $place, User $user, array $data): PaymentChannel
    {
        if (! in_array($place->territory_type->value, ['church', 'region'], true)) {
            throw ValidationException::withMessages(['territory_id' => ['The diocese\'s own payouts are set on Gateways.']]);
        }
        if (! Paystack::ready()) {
            throw ValidationException::withMessages(['bank_code' => ['Card giving isn\'t open yet - the diocese adds Paystack first.']]);
        }
        $banks = Paystack::diocese()->banks();
        if (! isset($banks[$data['bank_code']])) {
            throw ValidationException::withMessages(['bank_code' => ['Pick the bank from the list.']]);
        }
        $into = AccountingAccount::where('territory_id', $place->id)->where('cash_kind', 'bank')->find((int) $data['settles_into_id']);
        if (! $into) {
            throw ValidationException::withMessages(['settles_into_id' => ['Pick which of our bank accounts it lands in - add it under Cash & bank first.']]);
        }
        $ch = $this->channel($place) ?? new PaymentChannel(['territory_id' => $place->id, 'provider' => 'paystack', 'status' => 'pending', 'created_by' => $user->id]);
        $ch->fill([
            'request' => ['bank_code' => $data['bank_code'], 'bank_name' => $banks[$data['bank_code']], 'account_number' => $data['account_number'],
                'account_name' => trim($data['account_name']), 'settles_into_id' => $into->id],
            'requested_by' => $user->id, 'requested_at' => now(), 'review_note' => null,
        ])->save();

        return $ch;
    }

    public function withdraw(Territory $place): void
    {
        $ch = $this->channel($place);
        if (! $ch?->request) {
            throw ValidationException::withMessages(['request' => ['Nothing is waiting to be checked.']]);
        }
        $ch->subaccount_code ? $ch->update(['request' => null, 'requested_by' => null, 'requested_at' => null]) : $ch->delete();
    }

    /** The diocese checks a request: approve (Paystack makes or updates the subaccount) or send it back. */
    public function review(PaymentChannel $ch, User $user, string $decision, ?string $note, bool $switchOn): PaymentChannel
    {
        if (! $ch->request) {
            throw ValidationException::withMessages(['decision' => ['Nothing is waiting to be checked.']]);
        }
        if ($decision === 'return') {
            if (trim((string) $note) === '') {
                throw ValidationException::withMessages(['note' => ['Say what needs changing.']]);
            }
            $ch->update(['request' => null, 'review_note' => mb_substr(trim($note), 0, 255), 'checked_by' => $user->id, 'checked_at' => now()]);

            return $ch;
        }
        $r = (array) $ch->request;
        $place = Territory::findOrFail($ch->territory_id);
        try {
            if ($ch->subaccount_code) {
                Paystack::diocese()->updateSubaccount($ch->subaccount_code, ['settlement_bank' => $r['bank_code'], 'account_number' => $r['account_number']]);
            } else {
                $sub = Paystack::diocese()->createSubaccount($place->name, $r['bank_code'], $r['account_number'], 'Giving to '.$place->name.' ('.Paybill::code($place).')');
                $ch->subaccount_code = $sub['subaccount_code'] ?? null;
            }
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['decision' => [$e->getMessage()]]);
        }
        $ch->fill([
            'bank_code' => $r['bank_code'], 'bank_name' => $r['bank_name'], 'account_number' => $r['account_number'], 'account_name' => $r['account_name'],
            'settles_into_id' => $r['settles_into_id'], 'status' => $switchOn || $ch->status === 'active' ? 'active' : 'off',
            'request' => null, 'review_note' => null, 'checked_by' => $user->id, 'checked_at' => now(),
        ])->save();

        return $ch;
    }

    // ------------------------------------------------------------ recording payouts

    /**
     * Every Paystack payout of the last 30 days - each church's subaccount and
     * the diocese's main account - recorded once with its status; a paid one
     * posted (Dr the bank / Cr clearing) once; and matched to its gifts.
     */
    public function record(): int
    {
        $n = 0;
        $paystack = Paystack::diocese();
        $diocese = $this->paybill->diocese();
        $targets = PaymentChannel::where('provider', 'paystack')->whereNotNull('subaccount_code')->where('territory_id', '!=', $diocese->id)->get()
            ->map(fn ($c) => ['place' => $c->territory_id, 'code' => $c->subaccount_code, 'into' => $c->settles_into_id, 'main' => false])->all();
        $targets[] = ['place' => $diocese->id, 'code' => 'none', 'into' => $this->channel($diocese)?->settles_into_id, 'main' => true];
        foreach ($targets as $t) {
            $place = Territory::find($t['place']);
            if (! $place) {
                continue;
            }
            foreach ($paystack->settlements($t['code'], now()->subDays(30)->toDateString()) as $s) {
                $n += $this->recordOne($place, $t, (array) $s) ? 1 : 0;
            }
        }

        return $n;
    }

    /** One payout from Paystack; true when it was posted to the books now. */
    public function recordOne(Territory $place, array $t, array $s): bool
    {
        $id = (string) ($s['id'] ?? '');
        if ($id === '') {
            return false;
        }
        $row = PaystackSettlement::firstOrNew(['settlement_id' => $id]);
        if ($row->exists && ((int) $row->territory_id !== (int) $place->id || $row->main !== (bool) $t['main'])) {
            return false;   // already someone else's payout
        }
        $row->fill([
            'territory_id' => $place->id, 'main' => $t['main'], 'status' => strtolower((string) ($s['status'] ?? 'pending')),
            'amount' => round((int) ($s['effective_amount'] ?? $s['total_amount'] ?? 0) / 100, 2),
            'gross' => round((int) ($s['total_amount'] ?? $s['effective_amount'] ?? 0) / 100, 2),
            'fees' => round((int) ($s['total_fees'] ?? 0) / 100, 2),
            'settled_on' => substr((string) ($s['settlement_date'] ?? $s['settledAt'] ?? $s['createdAt'] ?? now()->toDateString()), 0, 10),
            'raw' => $s,
        ])->save();
        if ($row->matched === 'none' && (float) $row->amount > 0) {
            $this->match($row);
        }
        if (! $row->isPaid() || $row->journal_id || ! $t['into'] || (float) $row->amount <= 0) {
            return false;
        }

        return DB::transaction(function () use ($row, $place, $t) {
            $row = PaystackSettlement::whereKey($row->id)->lockForUpdate()->firstOrFail();
            if ($row->journal_id) {
                return false;
            }
            $j = $this->ledger->post($place, ['doc_type' => 'transfer', 'date' => $row->settled_on->toDateString(), 'narration' => 'Paystack payout', 'method' => 'bank',
                'reference' => "PSTK-{$row->settlement_id}", 'source_type' => 'paystack_settlement', 'source_id' => $row->id], [
                    ['account_id' => $t['into'], 'debit' => (float) $row->amount],
                    ['account_id' => $this->chart->account('online_clearing')->id, 'credit' => (float) $row->amount, 'memo' => 'Paystack payout'],
                ], null);
            $row->update(['journal_id' => $j->id]);

            return true;
        });
    }

    /** What of a gift a payout carries: the place's part (after the fee and the share) or the diocese's main account's part. */
    public static function part(Gift $g, bool $main): float
    {
        return $main ? (float) ($g->channel === 'subaccount' ? $g->split : $g->net) : (float) $g->net;
    }

    /** Gifts a payout may have carried, not yet matched to one. */
    private function candidates(PaystackSettlement $row, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $col = $row->main ? 'main_settlement_id' : 'settlement_id';

        return Gift::where('method', 'paystack')->whereIn('status', ['paid', 'refunded'])->whereNull($col)
            ->where('paid_at', '>=', $from->startOfDay()->utc())->where('paid_at', '<', $to->endOfDay()->utc())
            ->when(! $row->main, fn ($q) => $q->where('territory_id', $row->territory_id)->where('channel', 'subaccount'))
            ->when($row->main, fn ($q) => $q->where(fn ($w) => $w->where('channel', 'diocese')->orWhere(fn ($x) => $x->where('channel', 'subaccount')->where('split', '>', 0))))
            ->get()->filter(fn ($g) => self::part($g, $row->main) > 0);
    }

    /**
     * Which gifts a payout paid: the unmatched gifts of the day two days
     * before it (Lagos), else one, else three, else a run of up to five days
     * (a weekend). Exact = "adds up" and the gifts are linked to it; else the
     * nearest single day is noted as the "closest match" without linking, so
     * no gift is claimed by the wrong payout.
     */
    public function match(PaystackSettlement $row): void
    {
        $day = CarbonImmutable::parse($row->settled_on->toDateString(), self::TZ);
        $gifts = $this->candidates($row, $day->subDays(7), $day->subDay());
        $lagos = fn ($g) => $g->paid_at->copy()->setTimezone(self::TZ)->toDateString();
        $sumFor = fn (string $from, string $to) => round($gifts->filter(fn ($g) => $lagos($g) >= $from && $lagos($g) <= $to)->sum(fn ($g) => self::part($g, $row->main)), 2);
        $windows = [[2, 2], [1, 1], [3, 3]];
        foreach ([1, 2] as $end) {
            foreach ([2, 3, 4, 5] as $len) {
                $windows[] = [$end + $len - 1, $end];
            }
        }
        $amount = round((float) $row->amount, 2);
        foreach ($windows as [$back, $end]) {
            $from = $day->subDays($back)->toDateString();
            $to = $day->subDays($end)->toDateString();
            $sum = $sumFor($from, $to);
            if ($sum > 0 && abs($sum - $amount) < 0.01) {
                $ids = $gifts->filter(fn ($g) => $lagos($g) >= $from && $lagos($g) <= $to)->pluck('id');
                Gift::whereIn('id', $ids)->update([$row->main ? 'main_settlement_id' : 'settlement_id' => $row->id]);
                $row->update(['matched' => 'adds_up', 'covers_from' => $from, 'covers_to' => $to]);

                return;
            }
        }
        $best = collect([2, 1, 3])->map(fn ($b) => ['d' => $day->subDays($b)->toDateString(), 'sum' => $sumFor($day->subDays($b)->toDateString(), $day->subDays($b)->toDateString())])
            ->filter(fn ($x) => $x['sum'] > 0)->sortBy(fn ($x) => abs($x['sum'] - $amount))->first();
        if ($best) {
            $row->update(['matched' => 'closest', 'covers_from' => $best['d'], 'covers_to' => $best['d']]);
        }
    }

    // ------------------------------------------------------------ payouts per place

    /** Card money paid but not yet in a payout (matched or after the last one). */
    public function onTheWay(Territory $place): float
    {
        $main = $place->territory_type->value === 'diocese';
        $last = PaystackSettlement::where('territory_id', $place->id)->where('main', $main)->whereIn('status', ['success', 'processed'])->whereNotNull('covers_to')->max('covers_to');
        $col = $main ? 'main_settlement_id' : 'settlement_id';

        return round(Gift::where('method', 'paystack')->where('status', 'paid')->whereNull($col)
            ->when(! $main, fn ($q) => $q->where('territory_id', $place->id)->where('channel', 'subaccount'))
            ->when($main, fn ($q) => $q->where(fn ($w) => $w->where('channel', 'diocese')->orWhere(fn ($x) => $x->where('channel', 'subaccount')->where('split', '>', 0))))
            ->when($last, fn ($q) => $q->where('paid_at', '>=', CarbonImmutable::parse($last, self::TZ)->addDay()->startOfDay()->utc()))
            ->get()->sum(fn ($g) => self::part($g, $main)), 2);
    }

    public function list(Territory $place, int $year): array
    {
        $main = $place->territory_type->value === 'diocese';
        $paystack = PaystackSettlement::where('territory_id', $place->id)->where('main', $main)->whereYear('settled_on', $year)->orderByDesc('settled_on')->orderByDesc('id')->get();
        $numbers = Journal::whereIn('id', $paystack->pluck('journal_id')->filter())->pluck('number', 'id');
        $counts = Gift::whereIn($main ? 'main_settlement_id' : 'settlement_id', $paystack->pluck('id'))->selectRaw(($main ? 'main_settlement_id' : 'settlement_id').' as s, count(*) as n')->groupBy('s')->pluck('n', 's');
        $rows = $paystack->map(fn ($s) => [
            'id' => $s->id, 'kind' => 'paystack', 'date' => $s->settled_on->toDateString(), 'status' => $s->isPaid() ? 'paid' : ($s->status === 'failed' ? 'failed' : 'on_the_way'),
            'status_label' => PaystackSettlement::STATUSES[$s->status] ?? ucfirst($s->status), 'amount' => (float) $s->amount, 'fees' => (float) $s->fees,
            'covers_from' => $s->covers_from?->toDateString(), 'covers_to' => $s->covers_to?->toDateString(), 'matched' => $s->matched, 'matched_label' => PaystackSettlement::MATCHES[$s->matched],
            'gifts' => (int) ($counts[$s->id] ?? 0), 'receipt' => $numbers[$s->journal_id] ?? null,
        ]);
        $diocese = Remittance::where('to_territory_id', $place->id)->where('kind', 'settlement')->whereYear('sent_on', $year)->where('status', '!=', 'cancelled')->orderByDesc('sent_on')->get()
            ->map(fn ($r) => ['id' => $r->id, 'kind' => 'diocese', 'date' => $r->sent_on?->toDateString(), 'status' => $r->status === 'confirmed' ? 'paid' : ($r->status === 'waiting' ? 'on_the_way' : 'sent'),
                'status_label' => Remittance::STATUSES[$r->status], 'amount' => (float) $r->amount, 'fees' => 0.0, 'covers_from' => null, 'covers_to' => null,
                'matched' => null, 'matched_label' => $r->purpose, 'gifts' => null, 'receipt' => $r->number]);
        $all = $rows->concat($diocese)->sortByDesc('date')->values();
        $paid = $all->whereIn('status', ['paid', 'sent']);
        $last = $paid->first();
        $own = (bool) ($this->channel($place)?->isActive() && $this->channel($place)?->subaccount_code);

        return [
            'year' => $year, 'own_paystack' => $own || $main,
            'stats' => [
                'paid' => round($paid->sum('amount'), 2), 'count' => $paid->count(), 'average' => $paid->count() ? round($paid->sum('amount') / $paid->count(), 2) : 0,
                'last' => $last ? ['date' => $last['date'], 'amount' => $last['amount']] : null,
                'on_the_way' => $this->onTheWay($place), 'failed' => $all->where('status', 'failed')->count(),
                'held' => $main ? null : round($this->ledger->balance($place, $this->chart->account('held_by_diocese')), 2),
            ],
            'payouts' => $all,
        ];
    }

    /** One payout's gifts: gross less Paystack's fee less the diocese share = what it carried. */
    public function detail(PaystackSettlement $row): array
    {
        $col = $row->main ? 'main_settlement_id' : 'settlement_id';
        $gifts = $row->matched === 'adds_up' ? Gift::where($col, $row->id)->orderBy('paid_at')->get()
            : ($row->covers_from ? $this->candidates($row, CarbonImmutable::parse($row->covers_from->toDateString(), self::TZ), CarbonImmutable::parse($row->covers_to->toDateString(), self::TZ)) : collect());
        $places = Territory::whereIn('id', $gifts->pluck('territory_id')->unique())->pluck('name', 'id');
        $lines = $gifts->map(fn ($g) => [
            'reference' => $g->reference, 'giver' => $g->giver_name ?: 'Online giver', 'place' => $places[$g->territory_id] ?? null, 'paid_at' => $g->paid_at?->toIso8601String(),
            'purpose' => Paybill::PURPOSES[$g->purpose][0] ?? $g->purpose, 'gross' => (float) $g->amount, 'fee' => (float) $g->fee, 'share' => (float) $g->split,
            'part' => self::part($g, $row->main), 'refunded' => $g->status === 'refunded',
        ])->values();
        $sum = round($lines->sum('part'), 2);

        return [
            'id' => $row->id, 'date' => $row->settled_on->toDateString(), 'amount' => (float) $row->amount, 'fees' => (float) $row->fees, 'status' => $row->status,
            'status_label' => PaystackSettlement::STATUSES[$row->status] ?? ucfirst($row->status), 'main' => $row->main,
            'matched' => $row->matched, 'matched_label' => PaystackSettlement::MATCHES[$row->matched], 'covers_from' => $row->covers_from?->toDateString(), 'covers_to' => $row->covers_to?->toDateString(),
            'gifts' => $lines, 'totals' => ['gross' => round($lines->sum('gross'), 2), 'fee' => round($lines->sum('fee'), 2), 'share' => round($lines->sum('share'), 2), 'part' => $sum],
            'difference' => round((float) $row->amount - $sum, 2),
        ];
    }

    /** Gateways: every place's payouts in a period. */
    public function overview(string $from, string $to): array
    {
        $diocese = $this->paybill->diocese();
        $channels = PaymentChannel::where('provider', 'paystack')->get()->keyBy('territory_id');
        $settled = PaystackSettlement::whereBetween('settled_on', [$from, $to])->get()->groupBy('territory_id');
        $shares = Gift::where('method', 'paystack')->where('status', 'paid')->where('channel', 'subaccount')
            ->whereBetween('paid_at', [CarbonImmutable::parse($from, 'Africa/Nairobi')->startOfDay()->utc(), CarbonImmutable::parse($to, 'Africa/Nairobi')->endOfDay()->utc()])
            ->selectRaw('territory_id, SUM(split) as s')->groupBy('territory_id')->pluck('s', 'territory_id');
        $lastAll = PaystackSettlement::whereIn('status', ['success', 'processed'])->orderByDesc('settled_on')->get()->unique('territory_id')->keyBy('territory_id');

        return Territory::whereIn('territory_type', ['diocese', 'region', 'church'])->whereNotNull('code')->orderByRaw("FIELD(territory_type, 'diocese', 'region', 'church')")->orderBy('name')->get()
            ->map(function ($t) use ($diocese, $channels, $settled, $shares, $lastAll) {
                $ch = $channels[$t->id] ?? null;
                $rows = ($settled[$t->id] ?? collect());
                $paid = $rows->filter(fn ($s) => $s->isPaid());
                $isDiocese = (int) $t->id === (int) $diocese->id;
                $route = $isDiocese ? 'main' : ($ch && $ch->isActive() && $ch->subaccount_code ? 'own' : 'diocese');

                return [
                    'id' => $t->id, 'name' => $t->name, 'code' => Paybill::code($t), 'level' => $t->territory_type->value, 'route' => $route,
                    'payouts' => $paid->count(), 'paid' => round((float) $paid->sum('amount'), 2), 'failed' => $rows->where('status', 'failed')->count(),
                    'share' => round((float) ($shares[$t->id] ?? 0), 2),
                    'last' => isset($lastAll[$t->id]) ? ['date' => $lastAll[$t->id]->settled_on->toDateString(), 'amount' => (float) $lastAll[$t->id]->amount] : null,
                    'on_the_way' => $route === 'diocese' ? 0.0 : $this->onTheWay($t),
                    'waiting' => (bool) $ch?->request,
                ];
            })->values()->all();
    }

    // ------------------------------------------------------------ refunds and disputes

    /**
     * Paystack refunded a gift. A whole refund undoes it in both books (its
     * journals reversed, budget entries with them; a split-off share's
     * remittance cancelled); a part refund is kept on the gift to adjust by
     * a journal - never guessed. Once only.
     */
    public function refund(Gift $gift, float $amount, string $why): Gift
    {
        return DB::transaction(function () use ($gift, $amount, $why) {
            $gift = Gift::whereKey($gift->id)->lockForUpdate()->firstOrFail();
            if ($gift->status !== 'paid' || $gift->method !== 'paystack') {
                return $gift;
            }
            $amount = round($amount > 0 ? $amount : (float) $gift->amount, 2);
            if ($amount < (float) $gift->amount - 0.009) {
                $gift->update(['refunded_amount' => $amount, 'refunded_at' => now(), 'result' => mb_substr('Part refunded (KES '.number_format($amount, 2).") - adjust it with a journal. {$why}", 0, 255)]);

                return $gift->fresh();
            }
            $date = now('Africa/Nairobi')->toDateString();
            foreach ([$gift->journal_id, $gift->diocese_journal_id] as $id) {
                $j = $id ? Journal::find($id) : null;
                if ($j && $j->status !== 'reversed') {
                    $this->ledger->reverse($j, null, "Gift {$gift->reference} refunded on Paystack", max($date, $j->date->toDateString()));
                    $this->bridge->journalReversed($j, null);
                }
            }
            if ($gift->remittance_id) {
                Remittance::whereKey($gift->remittance_id)->update(['status' => 'cancelled']);
            }
            $gift->update(['status' => 'refunded', 'refunded_amount' => $amount, 'refunded_at' => now(), 'result' => mb_substr("Refunded on Paystack. {$why}", 0, 255)]);

            return $gift->fresh();
        });
    }

    public function disputed(Gift $gift): Gift
    {
        if ($gift->status === 'paid' && ! $gift->disputed_at) {
            $gift->update(['disputed_at' => now(), 'result' => 'Disputed by the giver\'s bank - Paystack will say how it ends.']);
        }

        return $gift->fresh();
    }

    /** A dispute ended: lost (the money goes back) is a whole refund; won clears the mark. */
    public function disputeResolved(Gift $gift, string $resolution, ?float $amount): Gift
    {
        if (in_array($resolution, ['merchant-accepted', 'auto-accepted'], true)) {
            return $this->refund($gift, (float) ($amount ?: $gift->amount), 'The dispute went to the giver.');
        }
        if ($gift->status === 'paid') {
            $gift->update(['disputed_at' => null, 'result' => 'Dispute ended in our favour.']);
        }

        return $gift->fresh();
    }
}
