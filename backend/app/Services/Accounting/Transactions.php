<?php

namespace App\Services\Accounting;

use App\Models\Gift;
use App\Models\Journal;
use App\Models\MpesaPayment;
use App\Models\MpesaRequest;
use App\Models\PaymentEvent;
use App\Models\Territory;
use App\Models\User;
use App\Services\Payments\Paystack;
use App\Support\PlaceAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Every attempt to pay, in one list (docs/specs/accounting-spec.md, A10e) -
 * the way v1-events shows its transactions, failed ones included:
 * - gifts from the giving page (M-Pesa or Paystack), whatever became of them;
 * - M-Pesa prompts that aren't gifts (Ask to pay, a share paid by M-Pesa);
 * - paybill payments typed by hand (C2B).
 * Each with who paid, how, where it stands, why it failed, and its receipt.
 */
final class Transactions
{
    public const SOURCES = ['gift', 'prompt', 'paybill', 'claim'];

    public const STATUSES = [
        'paid' => 'Paid', 'pending' => 'Waiting', 'failed' => 'Not paid', 'abandoned' => 'Abandoned', 'refunded' => 'Refunded',
        'to_sort' => 'To sort', 'returned' => 'Returned', 'checking' => 'Checking with Safaricom', 'waiting' => 'Claimed - to check',
    ];

    private const ROUTES = [
        'paybill' => 'Diocese paybill', 'payhero' => 'Own paybill (PayHero)', 'own_daraja' => 'Own paybill (Daraja)',
        'subaccount' => 'Paystack - own account', 'diocese' => 'Paystack - held by the diocese',
    ];

    public function __construct(private Paybill $paybill, private Giving $giving) {}

    /** The places whose transactions a place's page shows: itself, and for a region or the diocese the places below. @return array<int, int> */
    public function scope(Territory $place, ?int $only = null): array
    {
        $ids = $place->territory_type->value === 'church' ? [$place->id] : [$place->id, ...PlaceAccess::descendantIds($place)];

        return $only && in_array($only, $ids, true) ? [$only] : $ids;
    }

    /**
     * The list, filtered: from, to (dates), status, method (mpesa | card),
     * source, q (payer, phone, reference, M-Pesa code), place_id, page, per (up to 1000).
     */
    public function list(Territory $place, array $f): array
    {
        $from = CarbonImmutable::parse($f['from'] ?? now()->subDays(29)->toDateString(), 'Africa/Nairobi')->startOfDay();
        $to = CarbonImmutable::parse($f['to'] ?? now()->toDateString(), 'Africa/Nairobi')->endOfDay();
        $ids = $this->scope($place, isset($f['place_id']) ? (int) $f['place_id'] : null);
        $rows = $this->rows($ids, $from->utc(), $to->utc(), $place->territory_type->value === 'diocese' && empty($f['place_id']));
        $q = mb_strtolower(trim((string) ($f['q'] ?? '')));
        $filtered = $rows
            ->when(! empty($f['source']), fn ($c) => $c->where('source', $f['source']))
            ->when(! empty($f['method']), fn ($c) => $c->where('method', $f['method']))
            ->when($q !== '', fn ($c) => $c->filter(fn ($r) => str_contains(mb_strtolower(implode(' ', [$r['reference'], $r['code'], $r['payer']['name'], $r['payer']['phone'], $r['payer']['email'], $r['account_ref']])), $q)));
        $stats = $this->stats($filtered);
        $shown = ! empty($f['status']) ? $filtered->filter(fn ($r) => $f['status'] === 'failed' ? in_array($r['status'], ['failed', 'abandoned'], true) : $r['status'] === $f['status']) : $filtered;
        $page = max(1, (int) ($f['page'] ?? 1));
        $per = min(max((int) ($f['per'] ?? 50), 1), 1000);

        return [
            'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'stats' => $stats,
            'total' => $shown->count(), 'page' => $page, 'pages' => max(1, (int) ceil($shown->count() / $per)),
            'rows' => $shown->slice(($page - 1) * $per, $per)->values(),
            'places' => $place->territory_type->value === 'church' ? [] : Territory::whereIn('id', $this->scope($place))->whereNotNull('code')->orderBy('name')->get(['id', 'name'])->values(),
        ];
    }

    /** @return Collection<int, array> newest first */
    private function rows(array $ids, CarbonImmutable $from, CarbonImmutable $to, bool $withUnmatched): Collection
    {
        $places = Territory::whereIn('id', $ids)->pluck('name', 'id');
        $rows = collect();

        $gifts = Gift::whereIn('territory_id', $ids)->whereBetween('created_at', [$from, $to])->orderByDesc('id')->limit(5000)->get();
        $numbers = Journal::whereIn('id', $gifts->pluck('journal_id')->filter())->pluck('number', 'id');
        foreach ($gifts as $g) {
            $rows->push($this->giftRow($g, $places[$g->territory_id] ?? null, $numbers[$g->journal_id] ?? null));
        }

        $prompts = MpesaRequest::whereIn('territory_id', $ids)->whereBetween('created_at', [$from, $to])
            ->whereNotIn('id', Gift::whereNotNull('mpesa_request_id')->select('mpesa_request_id'))->orderByDesc('id')->limit(5000)->get();
        $payments = MpesaPayment::whereIn('id', $prompts->pluck('mpesa_payment_id')->filter())->get()->keyBy('id');
        $users = User::whereIn('id', $prompts->pluck('requested_by')->filter())->get()->keyBy('id');
        $pNumbers = Journal::whereIn('id', $payments->pluck('place_journal_id')->filter())->pluck('number', 'id');
        foreach ($prompts as $r) {
            $pay = $payments[$r->mpesa_payment_id] ?? null;
            $rows->push($this->promptRow($r, $places[$r->territory_id] ?? null, $pay, $users[$r->requested_by] ?? null, $pay ? ($pNumbers[$pay->place_journal_id] ?? null) : null));
        }

        $typed = MpesaPayment::where('kind', 'c2b')->whereBetween('paid_at', [$from, $to])
            ->where(fn ($w) => $w->whereIn('territory_id', $ids)->when($withUnmatched, fn ($x) => $x->orWhereNull('territory_id')))
            ->orderByDesc('paid_at')->limit(5000)->get();
        $tNumbers = Journal::whereIn('id', $typed->map(fn ($p) => $p->place_journal_id ?? $p->diocese_journal_id)->filter())->pluck('number', 'id');
        foreach ($typed as $p) {
            $rows->push($this->paybillRow($p, $p->territory_id ? ($places[$p->territory_id] ?? Territory::find($p->territory_id)?->name) : null, $tNumbers[$p->place_journal_id ?? $p->diocese_journal_id] ?? null));
        }

        // "I paid - here's my code" claims not (yet) confirmed: a confirmed one is its paybill payment above (A10f).
        foreach (\App\Models\PaymentClaim::whereIn('territory_id', $ids)->whereBetween('created_at', [$from, $to])->where('status', '!=', 'confirmed')->orderByDesc('id')->limit(2000)->get() as $c) {
            $rows->push($this->claimRow($c, $places[$c->territory_id] ?? null));
        }

        return $rows->sortByDesc('when')->values();
    }

    private function giftRow(Gift $g, ?string $place, ?string $receipt): array
    {
        $card = $g->method === 'paystack' && ! ($g->status === 'paid' && $g->result === 'mobile_money');

        return [
            'key' => "gift-{$g->id}", 'source' => 'gift', 'id' => $g->id, 'kind' => 'Gift', 'reference' => $g->reference,
            'when' => $g->created_at?->toIso8601String(), 'paid_at' => $g->paid_at?->toIso8601String(),
            'payer' => ['name' => $g->giver_name, 'phone' => $g->giver_phone, 'email' => $g->giver_email],
            'place' => ['id' => $g->territory_id, 'name' => $place], 'purpose' => GivingPurposes::label($g->purpose, $g->purpose),
            'method' => $card ? 'card' : 'mpesa', 'route' => self::ROUTES[$g->channel] ?? ($g->method === 'paystack' ? 'Paystack' : 'M-Pesa'),
            'amount' => (float) $g->amount, 'fee' => (float) $g->fee, 'status' => $g->status, 'status_label' => self::STATUSES[$g->status] ?? $g->status,
            'reason' => in_array($g->status, ['failed', 'abandoned', 'refunded'], true) || $g->disputed_at || (float) $g->refunded_amount > 0 ? $g->result : null,
            'disputed' => (bool) $g->disputed_at, 'code' => $g->status === 'paid' || $g->status === 'refunded' ? $g->provider_ref : null,
            'account_ref' => null, 'receipt' => $receipt,
        ];
    }

    private function promptRow(MpesaRequest $r, ?string $place, ?MpesaPayment $pay, ?User $by, ?string $receipt): array
    {
        return [
            'key' => "prompt-{$r->id}", 'source' => 'prompt', 'id' => $r->id, 'kind' => $r->remittance_id ? 'Diocese share' : 'Ask to pay', 'reference' => $r->account_ref,
            'when' => $r->created_at?->toIso8601String(), 'paid_at' => $pay?->paid_at?->toIso8601String(),
            'payer' => ['name' => $pay?->payer_name, 'phone' => $r->phone, 'email' => null],
            'place' => ['id' => $r->territory_id, 'name' => $place], 'purpose' => $r->remittance_id ? 'Diocese share' : $this->purposeOf($r->account_ref),
            'method' => 'mpesa', 'route' => $r->channel_id ? 'Own paybill' : 'Diocese paybill', 'requested_by' => $by?->name,
            'amount' => (float) $r->amount, 'fee' => 0.0, 'status' => $r->status, 'status_label' => self::STATUSES[$r->status] ?? $r->status,
            'reason' => $r->status === 'failed' ? $r->result : null, 'disputed' => false, 'code' => $pay?->trans_id,
            'account_ref' => $r->account_ref, 'receipt' => $receipt,
        ];
    }

    private function paybillRow(MpesaPayment $p, ?string $place, ?string $receipt): array
    {
        $status = ['posted' => 'paid', 'to_sort' => 'to_sort', 'returned' => 'returned'][$p->status] ?? $p->status;

        return [
            'key' => "paybill-{$p->id}", 'source' => 'paybill', 'id' => $p->id, 'kind' => $p->remittance_id ? 'Diocese share' : 'Paybill payment', 'reference' => $p->trans_id,
            'when' => $p->paid_at?->toIso8601String(), 'paid_at' => $p->paid_at?->toIso8601String(),
            'payer' => ['name' => $p->payer_name, 'phone' => $p->phone, 'email' => null],
            'place' => ['id' => $p->territory_id, 'name' => $place], 'purpose' => $p->remittance_id ? 'Diocese share' : (GivingPurposes::label($p->purpose, 'Not matched')),
            'method' => 'mpesa', 'route' => $p->channel_id ? 'Own paybill' : 'Diocese paybill',
            'amount' => (float) $p->amount, 'fee' => 0.0, 'status' => $status, 'status_label' => self::STATUSES[$status] ?? $status,
            'reason' => $p->status !== 'posted' ? $p->note : null, 'disputed' => false, 'code' => $p->trans_id,
            'account_ref' => $p->bill_ref, 'receipt' => $receipt,
        ];
    }

    private function claimRow(\App\Models\PaymentClaim $c, ?string $place): array
    {
        $status = $c->status === 'failed' ? 'failed' : ($c->status === 'checking' ? 'checking' : 'waiting');

        return [
            'key' => "claim-{$c->id}", 'source' => 'claim', 'id' => $c->id, 'kind' => 'Paid by Pay Bill - claimed', 'reference' => $c->trans_id,
            'when' => $c->created_at?->toIso8601String(), 'paid_at' => null,
            'payer' => ['name' => $c->giver_name, 'phone' => $c->giver_phone, 'email' => null],
            'place' => ['id' => $c->territory_id, 'name' => $place], 'purpose' => GivingPurposes::label($c->purpose, $c->purpose),
            'method' => 'mpesa', 'route' => 'Diocese paybill', 'amount' => (float) ($c->amount ?? 0), 'fee' => 0.0, 'status' => $status, 'status_label' => self::STATUSES[$status],
            'reason' => $c->result, 'disputed' => false, 'code' => $c->trans_id, 'account_ref' => null, 'receipt' => null,
        ];
    }

    private function purposeOf(?string $ref): string
    {
        $parsed = $this->paybill->parse($ref);

        return $parsed['purpose'] ? GivingPurposes::label($parsed['purpose'], 'Ask to pay') : 'Ask to pay';
    }

    private function stats(Collection $rows): array
    {
        $paid = $rows->where('status', 'paid');
        $failed = $rows->whereIn('status', ['failed', 'abandoned']);
        $tried = $paid->count() + $failed->count();

        return [
            'paid' => round($paid->sum('amount'), 2), 'paid_count' => $paid->count(),
            'failed' => $failed->count(), 'pending' => $rows->where('status', 'pending')->count(),
            'waiting_long' => $rows->where('status', 'pending')->filter(fn ($r) => $r['when'] && CarbonImmutable::parse($r['when'])->lt(now()->subMinutes(10)))->count(),
            'success_rate' => $tried ? (int) round($paid->count() * 100 / $tried) : null,
            'fees' => round($rows->sum('fee'), 2), 'to_sort' => $rows->where('status', 'to_sort')->count(),
            'by_method' => ['mpesa' => round($paid->where('method', 'mpesa')->sum('amount'), 2), 'card' => round($paid->where('method', 'card')->sum('amount'), 2)],
        ];
    }

    // ------------------------------------------------------------ one transaction

    /** @return array{0: Gift|MpesaRequest|MpesaPayment, 1: ?int} the record and its place */
    public function find(string $source, int $id): array
    {
        $model = match ($source) {
            'gift' => Gift::find($id),
            'prompt' => MpesaRequest::find($id),
            'paybill' => MpesaPayment::find($id),
            'claim' => \App\Models\PaymentClaim::find($id),
            default => null,
        };

        return [$model, $model?->territory_id];
    }

    /** The detail: the row, where the money went, and what happened when - including every callback. */
    public function detail(string $source, $model): array
    {
        $place = $model->territory_id ? Territory::find($model->territory_id)?->name : null;
        if ($source === 'gift') {
            $row = $this->giftRow($model, $place, Journal::find($model->journal_id)?->number);
            $request = $model->mpesa_request_id ? MpesaRequest::find($model->mpesa_request_id) : null;
            $steps = [['at' => $model->created_at, 'what' => 'Started on the giving page', 'detail' => $model->method === 'mpesa' ? 'By M-Pesa' : 'By card or M-Pesa on Paystack\'s page', 'tone' => 'primary']];
            if ($request) {
                $steps[] = ['at' => $request->created_at, 'what' => 'M-Pesa prompt sent', 'detail' => "To {$request->phone}".($request->checkout_request_id ? '' : ' - M-Pesa didn\'t take it'), 'tone' => 'primary'];
            }
            $needles = array_filter([$model->reference, $request?->checkout_request_id]);
            $money = $model->method === 'paystack' ? ['gross' => (float) $model->amount, 'fee' => (float) $model->fee, 'share' => (float) $model->split, 'net' => (float) $model->net] : null;
            $journals = array_filter([$model->journal_id, $model->diocese_journal_id]);
        } elseif ($source === 'prompt') {
            $pay = $model->mpesa_payment_id ? MpesaPayment::find($model->mpesa_payment_id) : null;
            $row = $this->promptRow($model, $place, $pay, User::find($model->requested_by), $pay ? Journal::find($pay->place_journal_id)?->number : null);
            $steps = [['at' => $model->created_at, 'what' => 'M-Pesa prompt sent', 'detail' => "To {$model->phone}".($model->requested_by ? ' by '.User::find($model->requested_by)?->name : ''), 'tone' => 'primary']];
            $needles = array_filter([$model->checkout_request_id]);
            $money = null;
            $journals = array_filter([$pay?->place_journal_id, $pay?->diocese_journal_id]);
        } elseif ($source === 'claim') {
            $row = $this->claimRow($model, $place);
            $steps = [['at' => $model->created_at, 'what' => 'Claimed: "I paid by Pay Bill"', 'detail' => "Code {$model->trans_id}".($model->giver_phone ? " from {$model->giver_phone}" : ''), 'tone' => 'primary']];
            $needles = array_filter([$model->originator_id, $model->conversation_id]);
            $money = null;
            $journals = [];
        } else {
            $row = $this->paybillRow($model, $place, Journal::find($model->place_journal_id ?? $model->diocese_journal_id)?->number);
            $steps = [['at' => $model->paid_at, 'what' => 'Paid into the paybill', 'detail' => "Account number {$model->bill_ref}", 'tone' => 'success']];
            $needles = [$model->trans_id];
            $money = null;
            $journals = array_filter([$model->place_journal_id, $model->diocese_journal_id, $model->sort_journal_id]);
        }
        $start = CarbonImmutable::parse($row['when'] ?? now())->subMinute();
        foreach ($needles ? PaymentEvent::where('created_at', '>=', $start)->where('created_at', '<=', $start->addDays(3))
            ->where(fn ($w) => collect($needles)->each(fn ($n) => $w->orWhereRaw('CAST(payload AS CHAR) LIKE ?', ['%'.$n.'%'])))->orderBy('id')->limit(30)->get() : [] as $e) {
            $who = ['daraja' => 'Safaricom', 'paystack' => 'Paystack', 'payhero' => 'PayHero'][$e->provider] ?? ucfirst($e->provider);
            $steps[] = ['at' => $e->created_at, 'what' => "{$who} answered".($e->kind ? ' ('.str_replace('_', ' ', $e->kind).')' : ''),
                'detail' => $e->error ?: ($e->status === 'handled' ? 'Accepted' : ucfirst($e->status)), 'tone' => $e->status === 'failed' || ($e->error && $e->status !== 'handled') ? 'danger' : 'secondary'];
        }
        if ($row['status'] === 'paid' || $row['status'] === 'refunded') {
            $steps[] = ['at' => CarbonImmutable::parse($row['paid_at'] ?? $row['when']), 'what' => 'Paid', 'detail' => $row['code'] ? "Reference {$row['code']}" : '', 'tone' => 'success'];
        } elseif (in_array($row['status'], ['failed', 'abandoned'], true)) {
            $steps[] = ['at' => $model->updated_at, 'what' => self::STATUSES[$row['status']], 'detail' => (string) $row['reason'], 'tone' => 'danger'];
        }
        foreach (Journal::whereIn('id', $journals)->get() as $j) {
            $steps[] = ['at' => $j->created_at, 'what' => 'In the books', 'detail' => "{$j->number} - ".Territory::find($j->territory_id)?->name, 'tone' => 'success', 'journal_id' => $j->id];
        }
        if ($source === 'gift' && $model->disputed_at) {
            $steps[] = ['at' => $model->disputed_at, 'what' => 'Disputed by the giver\'s bank', 'detail' => '', 'tone' => 'danger'];
        }
        if ($source === 'gift' && $model->refunded_at) {
            $steps[] = ['at' => $model->refunded_at, 'what' => 'Refunded', 'detail' => 'KES '.number_format((float) $model->refunded_amount, 2), 'tone' => 'danger'];
        }
        usort($steps, fn ($a, $b) => strcmp((string) $a['at'], (string) $b['at']));

        return $row + [
            'money' => $money,
            'steps' => array_map(fn ($s) => ['at' => $s['at'] ? CarbonImmutable::parse($s['at'])->toIso8601String() : null] + $s, $steps),
            'can_check' => in_array($row['status'], ['pending', 'waiting', 'checking'], true),
        ];
    }

    /** Ask the provider again about one waiting transaction. */
    public function check(string $source, $model): void
    {
        if ($source === 'gift' && $model->status === 'pending') {
            if ($model->method === 'paystack') {
                if (! Paystack::ready()) {
                    throw ValidationException::withMessages(['check' => ['Paystack isn\'t set up.']]);
                }
                $this->giving->complete($model);

                return;
            }
            $model = $model->mpesa_request_id ? MpesaRequest::find($model->mpesa_request_id) : null;
            $source = 'prompt';
        }
        if ($source === 'claim' && in_array($model->status, ['waiting', 'checking', 'failed'], true)) {
            // Ask Safaricom again about the same claim.
            app(PaybillClaims::class)->claim(Territory::findOrFail($model->territory_id), ['code' => $model->trans_id, 'purpose' => $model->purpose, 'name' => $model->giver_name, 'phone' => $model->giver_phone], null, auth()->user() ?? User::find($model->claimed_by), true);

            return;
        }
        if ($source === 'prompt' && $model?->status === 'pending') {
            \Illuminate\Support\Facades\Cache::forget("prompt-check:{$model->id}");
            $this->paybill->checkPrompt($model);
        }
    }

    /** Check everything still waiting at these places (up to 20 at a time). @return array{checked: int, paid: int, failed: int} */
    public function checkWaiting(array $ids): array
    {
        $done = ['checked' => 0, 'paid' => 0, 'failed' => 0];
        $waiting = Gift::whereIn('territory_id', $ids)->where('status', 'pending')->where('created_at', '<', now()->subMinutes(2))->orderBy('id')->limit(20)->get()->map(fn ($g) => ['gift', $g])
            ->concat(MpesaRequest::whereIn('territory_id', $ids)->where('status', 'pending')->where('created_at', '<', now()->subMinutes(2))
                ->whereNotIn('id', Gift::whereNotNull('mpesa_request_id')->select('mpesa_request_id'))->orderBy('id')->limit(20)->get()->map(fn ($r) => ['prompt', $r]))
            ->take(20);
        foreach ($waiting as [$source, $model]) {
            try {
                $done['checked']++;
                $this->check($source, $model);
                $status = $model->fresh()->status;
                $done['paid'] += $status === 'paid' ? 1 : 0;
                $done['failed'] += in_array($status, ['failed', 'abandoned'], true) ? 1 : 0;
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $done;
    }
}
