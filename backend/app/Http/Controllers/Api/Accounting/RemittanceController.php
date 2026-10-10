<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Models\Remittance;
use App\Models\Territory;
use App\Services\Accounting\Chart;
use App\Services\Accounting\Remittances;
use App\Support\AccountingAccess;
use App\Support\PlaceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Remittances between levels (docs/specs/accounting-spec.md, A6). The
 * sending place's book-readers see what they owe and sent, and whoever
 * prepares payments sends; the receiving place's book-readers see what is
 * coming in, and whoever writes receipts confirms or queries it. A region or
 * the diocese sees the places below it on the board.
 */
class RemittanceController extends AccountingBase
{
    public function __construct(private Remittances $remittances, private Chart $chart) {}

    /** GET /accounting/remittances?year= - what we owe, what we sent, what is coming in. */
    public function index(Request $request): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $year = $this->year($request);
        $user = $request->user();
        $out = Remittance::with(['to', 'lines'])->where('from_territory_id', $place->id)->orderByDesc('id')->limit(300)->get();
        $in = Remittance::with(['from', 'lines'])->where('to_territory_id', $place->id)->where('status', '!=', 'waiting')->where('status', '!=', 'cancelled')->orderByDesc('id')->limit(300)->get();

        return $this->ok([
            'place' => $this->placeInfo($place),
            'year' => $year,
            'can' => $this->can($request, $place),
            'owing' => $this->remittances->owing($place, $year),
            'sent' => $out->map(fn ($r) => $this->present($r, $user))->values(),
            'coming_in' => $in->map(fn ($r) => $this->present($r, $user))->values(),
            'has_below' => $place->territory_type->value !== 'church',
        ]);
    }

    /** GET /accounting/remittances/options - where it is paid from or lands, places below for support, expenses. */
    public function options(Request $request): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $below = $place->territory_type->value === 'church' ? collect()
            : Territory::whereIn('id', PlaceAccess::descendantIds($place))->where('id', '!=', $place->id)->orderBy('name')->get(['id', 'name', 'code', 'territory_type']);

        return $this->ok([
            'cash' => $this->chart->cashAccounts($place)->map(fn ($a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'cash_kind' => $a->cash_kind])->values(),
            'below' => $below->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'code' => $t->code, 'level' => $t->territory_type->value])->values(),
            'expenses' => $this->chart->usable($place)->filter(fn ($a) => $a->type === 'expense')->map(fn ($a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name])->values(),
            'support_code' => '5600',
            'today' => now()->toDateString(),
        ]);
    }

    /** POST /accounting/remittances {kind: share|support, ...} - the voucher is prepared and goes for approval. */
    public function store(Request $request): JsonResponse
    {
        $place = $this->place($request, 'prepare');
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $data = $request->validate([
            'kind' => ['required', 'in:share,support'],
            'pay_from_account_id' => ['required', 'integer'],
            'budget_deduction_id' => ['required_if:kind,share', 'nullable', 'integer'],
            'lines' => ['required_if:kind,share', 'array', 'max:12'],
            'lines.*.month' => ['required', 'string', 'size:7'],
            'lines.*.amount' => ['nullable', 'numeric', 'min:0'],
            'to_territory_id' => ['required_if:kind,support', 'nullable', 'integer'],
            'amount' => ['required_if:kind,support', 'nullable', 'numeric', 'min:1'],
            'purpose' => ['required_if:kind,support', 'nullable', 'string', 'max:255'],
            'account_id' => ['nullable', 'integer'],
        ], ['pay_from_account_id.required' => 'Pick where it is paid from.', 'purpose.required_if' => 'Say what the support is for.']);
        $r = $data['kind'] === 'share'
            ? $this->remittances->sendShare($place, $request->user(), $data)
            : $this->remittances->sendSupport($place, $request->user(), $data);

        return $this->ok($this->present($r->fresh(), $request->user(), true), "{$r->number} is ready - its voucher goes for approval, then it is paid.", 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        [$r, , $deny] = $this->remittance($request, $id);

        return $deny ?? $this->ok($this->present($r, $request->user(), true));
    }

    /** POST /accounting/remittances/{id}/confirm {into_account_id, received_on} - the receiving place. */
    public function confirm(Request $request, int $id): JsonResponse
    {
        [$r, , $deny] = $this->remittance($request, $id, 'to', 'receipt');
        if ($deny) {
            return $deny;
        }
        $data = $request->validate(['into_account_id' => ['required', 'integer'], 'received_on' => ['required', 'date']], ['into_account_id.required' => 'Pick the account it reached.']);
        $r = $this->remittances->confirm($r, $request->user(), $data);

        return $this->ok($this->present($r, $request->user(), true), 'Confirmed - the receipt is in your books.');
    }

    public function unconfirm(Request $request, int $id): JsonResponse
    {
        [$r, , $deny] = $this->remittance($request, $id, 'to', 'receipt');
        if ($deny) {
            return $deny;
        }
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], ['reason.required' => 'Say why.']);

        return $this->ok($this->present($this->remittances->unconfirm($r, $request->user(), $data['reason']), $request->user(), true), 'Undone - the receipt is reversed.');
    }

    public function query(Request $request, int $id): JsonResponse
    {
        [$r, , $deny] = $this->remittance($request, $id, 'to', 'receipt');
        if ($deny) {
            return $deny;
        }
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], ['reason.required' => 'Say what is wrong.']);

        return $this->ok($this->present($this->remittances->query($r, $request->user(), $data['reason']), $request->user(), true), 'Queried - the sender is asked.');
    }

    public function answer(Request $request, int $id): JsonResponse
    {
        [$r, , $deny] = $this->remittance($request, $id, 'from', 'prepare');
        if ($deny) {
            return $deny;
        }
        $data = $request->validate(['answer' => ['required', 'string', 'max:255']], ['answer.required' => 'Write your answer.']);

        return $this->ok($this->present($this->remittances->answer($r, $request->user(), $data['answer']), $request->user(), true), 'Answered - it is with them again.');
    }

    /** GET /accounting/remittances/board?year= - the places below (region or diocese). */
    public function board(Request $request): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        if ($place->territory_type->value === 'church') {
            return $this->forbidden('A church has no places below it.');
        }
        if (! AccountingAccess::abilities($request->user(), $place)['below']) {
            return $this->forbidden('Your role doesn\'t see the places below.');
        }
        $year = $this->year($request);

        return $this->ok(['year' => $year, 'rows' => $this->remittances->board($place, $year)]);
    }

    /** GET /accounting/remittances/statement?place=&year= - one place below, with us. */
    public function statement(Request $request): JsonResponse
    {
        $owner = $this->place($request);
        if ($owner instanceof JsonResponse) {
            return $owner;
        }
        $place = Territory::find((int) $request->query('place'));
        if (! $place || ! in_array((int) $place->id, PlaceAccess::descendantIds($owner), true) || ! AccountingAccess::abilities($request->user(), $owner)['below']) {
            return $this->notFound('That place isn\'t below yours.');
        }

        return $this->ok($this->remittances->statement($owner, $place, $this->year($request)));
    }

    // ------------------------------------------------------------ helpers

    private function year(Request $request): int
    {
        $y = (int) $request->query('year', now()->year);

        return $y >= 2020 && $y <= (int) now()->year + 1 ? $y : (int) now()->year;
    }

    /**
     * A remittance either side may see. With $side and $ability, only that
     * side's people with that ability, in their own books.
     *
     * @return array{0: ?Remittance, 1: ?Territory, 2: ?JsonResponse}
     */
    private function remittance(Request $request, int $id, ?string $side = null, ?string $ability = null): array
    {
        $r = Remittance::find($id);
        $user = $request->user();
        if (! $r) {
            return [null, null, $this->notFound('That remittance isn\'t here.')];
        }
        $from = Territory::find($r->from_territory_id);
        $to = Territory::find($r->to_territory_id);
        $sees = ($from && AccountingAccess::canRead($user, $from)) || ($to && AccountingAccess::canRead($user, $to) && $r->status !== 'waiting');
        if (! $sees) {
            return [null, null, $this->notFound('That remittance isn\'t yours to see.')];
        }
        if ($side) {
            $place = $side === 'to' ? $to : $from;
            if (! $place || ! AccountingAccess::can($user, $place, $ability)) {
                return [null, null, $this->forbidden($side === 'to' ? 'Whoever writes receipts where it was sent confirms it.' : 'Whoever prepares payments where it came from answers.')];
            }

            return [$r, $place, null];
        }

        return [$r, $from, null];
    }

    private function can(Request $request, Territory $place): array
    {
        $a = AccountingAccess::abilities($request->user(), $place);

        return ['send' => $a['prepare'], 'confirm' => $a['receipt'], 'below' => $a['below'] && $place->territory_type->value !== 'church', 'books' => $a['read'], 'own' => $a['own']];
    }

    private function present(Remittance $r, $user, bool $full = false): array
    {
        $r->loadMissing(['from', 'to', 'lines', 'voucher', 'deduction']);
        $toSide = $r->to && AccountingAccess::can($user, $r->to, 'receipt');
        $fromSide = $r->from && AccountingAccess::can($user, $r->from, 'prepare');
        $out = [
            'id' => $r->id,
            'number' => $r->number,
            'kind' => $r->kind,
            'kind_label' => Remittance::KINDS[$r->kind],
            'purpose' => $r->purpose,
            'amount' => (float) $r->amount,
            'status' => $r->status,
            'status_label' => Remittance::STATUSES[$r->status],
            'from' => $r->from ? ['id' => $r->from->id, 'name' => $r->from->name] : null,
            'to' => $r->to ? ['id' => $r->to->id, 'name' => $r->to->name] : null,
            'months' => $r->lines->pluck('month')->values(),
            'sent_on' => $r->sent_on?->toDateString(),
            'received_on' => $r->received_on?->toDateString(),
            'created_at' => $r->created_at?->toIso8601String(),
            'voucher' => $r->voucher ? ['id' => $r->voucher->id, 'number' => $r->voucher->number, 'status' => $r->voucher->status] : null,
            'query_reason' => $r->query_reason,
            'answer' => $r->answer,
            'can' => [
                'confirm' => $toSide && in_array($r->status, ['sent', 'queried'], true),
                'query' => $toSide && $r->status === 'sent',
                'unconfirm' => $toSide && $r->status === 'confirmed',
                'answer' => $fromSide && $r->status === 'queried',
            ],
        ];
        if ($full) {
            $out += [
                'lines' => $r->lines->map(fn ($l) => ['month' => $l->month, 'amount' => (float) $l->amount, 'due' => $l->due !== null ? (float) $l->due : null])->values(),
                'deduction' => $r->deduction?->name,
                'method' => $r->method,
                'reference' => $r->reference,
                'confirmed_by' => $r->confirmer?->full_name,
                'received_journal_id' => $r->received_journal_id,
            ];
        }

        return $out;
    }
}
