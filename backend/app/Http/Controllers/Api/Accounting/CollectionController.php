<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Models\Collection;
use App\Models\Territory;
use App\Services\Accounting\Books;
use App\Services\Accounting\Chart;
use App\Services\Accounting\Collections;
use App\Support\AccountingAccess;
use App\Support\PlaceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sunday collections (docs/specs/accounting-spec.md, A3): counted by one,
 * confirmed by another - which receipts it per fund - then banked. Ushers
 * and elders who count and confirm see the collections, not the whole books.
 */
class CollectionController extends AccountingBase
{
    public function __construct(private Collections $collections, private Books $books, private Chart $chart) {}

    /** GET /accounting/collections */
    public function index(Request $request): JsonResponse
    {
        $place = $this->church($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $items = Collection::with(['lines', 'counter', 'confirmer', 'journal:id,number', 'bankingJournal:id,number,date'])
            ->where('territory_id', $place->id)->orderByDesc('date')->orderByDesc('id')->limit(500)->get();

        return $this->ok([
            'place' => $this->placeInfo($place),
            'can' => $this->can($request, $place),
            'me' => $request->user()->id,
            'items' => $items->map(fn ($c) => $this->present($c))->values(),
            'monthly' => $this->monthly($place),
            'unbanked' => Collections::unbanked($place),
        ]);
    }

    /** GET /accounting/collections/options?date= - kinds of giving, money accounts, that day's gatherings. */
    public function options(Request $request): JsonResponse
    {
        $place = $this->church($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $date = $request->validate(['date' => ['nullable', 'date']])['date'] ?? null;
        $cash = $this->chart->cashAccounts($place)->map(fn ($a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'cash_kind' => $a->cash_kind])->values();
        $income = $this->chart->usable($place)->whereIn('type', ['income', 'liability'])->map(fn ($a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'type' => $a->type])->values();

        return $this->ok($this->collections->options($place, $date) + [
            'cash' => $cash,
            'accounts' => $income,
            'funds' => \App\Models\AccountingFund::where('is_active', true)->orderBy('display_order')->get(['id', 'code', 'name', 'is_restricted']),
            'today' => now()->toDateString(),
            'denominations' => \App\Models\CashCount::DENOMINATIONS,
        ]);
    }

    /** GET /accounting/collections/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        [$c, $place, $deny] = $this->collection($request, $id);

        return $deny ?? $this->ok($this->full($request, $c, $place));
    }

    /** POST /accounting/collections */
    public function store(Request $request): JsonResponse
    {
        $place = $this->church($request, 'collect');
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $c = $this->collections->record($place, $request->user(), $this->validated($request));

        return $this->ok($this->full($request, $c, $place), 'Counted - a second person now confirms it.', 201);
    }

    /** PUT /accounting/collections/{id} */
    public function update(Request $request, int $id): JsonResponse
    {
        [$c, $place, $deny] = $this->collection($request, $id, 'collect');
        if ($deny) {
            return $deny;
        }
        $c = $this->collections->update($c, $request->user(), $this->validated($request));

        return $this->ok($this->full($request, $c, $place), 'Saved - it waits to be confirmed.');
    }

    /** DELETE /accounting/collections/{id} */
    public function destroy(Request $request, int $id): JsonResponse
    {
        [$c, , $deny] = $this->collection($request, $id, 'collect');
        if ($deny) {
            return $deny;
        }
        $this->collections->delete($c);

        return $this->ok(null, 'Deleted.');
    }

    /** POST /accounting/collections/{id}/confirm */
    public function confirm(Request $request, int $id): JsonResponse
    {
        [$c, $place, $deny] = $this->collection($request, $id, 'confirm');
        if ($deny) {
            return $deny;
        }
        $c = $this->collections->confirm($c, $request->user());

        return $this->ok($this->full($request, $c, $place), 'Confirmed - receipt '.($c->journal?->number ?? '').' written.');
    }

    /** POST /accounting/collections/{id}/return {reason} */
    public function sendBack(Request $request, int $id): JsonResponse
    {
        [$c, $place, $deny] = $this->collection($request, $id, 'confirm');
        if ($deny) {
            return $deny;
        }
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], ['reason.required' => 'Say what needs checking.']);

        return $this->ok($this->full($request, $this->collections->sendBack($c, $request->user(), $data['reason']), $place), 'Sent back to be checked.');
    }

    /** POST /accounting/collections/{id}/bank {to_account_id, date, amount?, reference?} */
    public function bank(Request $request, int $id): JsonResponse
    {
        [$c, $place, $deny] = $this->collection($request, $id, 'receipt');
        if ($deny) {
            return $deny;
        }
        $data = $request->validate(['to_account_id' => ['required', 'integer'], 'date' => ['required', 'date'], 'amount' => ['nullable', 'numeric', 'min:0.01'], 'reference' => ['nullable', 'string', 'max:100']]);
        $c = $this->collections->bank($c, $request->user(), $data);

        return $this->ok($this->full($request, $c, $place), 'Banked - '.($c->bankingJournal?->number ?? '').'.');
    }

    /** POST /accounting/collections/{id}/reverse {reason} */
    public function reverse(Request $request, int $id): JsonResponse
    {
        [$c, $place, $deny] = $this->collection($request, $id, 'journal');
        if ($deny) {
            return $deny;
        }
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], ['reason.required' => 'Say why it is being reversed.']);

        return $this->ok($this->full($request, $this->collections->reverse($c, $request->user(), $data['reason']), $place), 'Reversed.');
    }

    // ------------------------------------------------------------------ helpers

    /** The church whose collections these are (or one below, to read). */
    private function church(Request $request, ?string $ability = null): Territory|JsonResponse
    {
        $id = $request->input('territory_id', $request->query('territory_id'));
        $place = PlaceAccess::place($request->user(), $id !== null && ctype_digit((string) $id) ? (int) $id : null);
        if (! $place || ! AccountingAccess::canSeeCollections($request->user(), $place)) {
            return $this->forbidden('These collections aren\'t yours to see.');
        }
        if ($place->territory_type->value !== 'church') {
            return $this->forbidden('Collections are counted by churches.');
        }
        if ($ability && ! AccountingAccess::can($request->user(), $place, $ability)) {
            return $this->forbidden($ability === 'collect' ? 'Your role can\'t record collections.' : 'Your role can\'t do that here.');
        }

        return $place;
    }

    /** @return array{0: ?Collection, 1: ?Territory, 2: ?JsonResponse} */
    private function collection(Request $request, int $id, ?string $ability = null): array
    {
        $c = Collection::find($id);
        $place = $c ? Territory::find($c->territory_id) : null;
        if (! $c || ! $place || ! AccountingAccess::canSeeCollections($request->user(), $place)) {
            return [null, null, $this->notFound('That collection isn\'t yours to see.')];
        }
        if ($ability && ! AccountingAccess::can($request->user(), $place, $ability)) {
            return [null, null, $this->forbidden(match ($ability) {
                'confirm' => 'Your role can\'t confirm collections.',
                'receipt' => 'Your role can\'t bank money.',
                'journal' => 'Only whoever keeps the books can reverse a collection.',
                default => 'Your role can\'t change collections.',
            })];
        }

        return [$c, $place, null];
    }

    private function can(Request $request, Territory $place): array
    {
        $a = AccountingAccess::abilities($request->user(), $place);

        return ['collect' => $a['collect'], 'confirm' => $a['confirm'], 'bank' => $a['receipt'], 'reverse' => $a['journal'], 'books' => $a['read'], 'own' => $a['own']];
    }

    private function full(Request $request, Collection $c, Territory $place): array
    {
        $c->loadMissing(['lines.account:id,code,name', 'lines.fund:id,code,name', 'counter', 'confirmer', 'journal', 'bankingJournal', 'cashAccount', 'mpesaAccount', 'attendance.gatheringType']);
        $me = $request->user()->id;
        $can = $this->can($request, $place);

        return $this->present($c, true) + [
            'place' => $this->placeInfo($place),
            'can' => $can + [
                'edit' => $can['collect'] && in_array($c->status, ['counted', 'returned'], true),
                'confirm_this' => $can['confirm'] && $c->status === 'counted' && (int) $c->counted_by !== $me,
                'return_this' => $can['confirm'] && $c->status === 'counted',
                'bank_this' => $can['bank'] && $c->status === 'posted' && ! $c->banking_journal_id && (float) $c->cash_total > 0,
                'reverse_this' => $can['reverse'] && $c->status === 'posted' && ! $c->banking_journal_id,
                'delete_this' => $can['collect'] && in_array($c->status, ['counted', 'returned'], true),
                'counted_this' => (int) $c->counted_by === $me,
            ],
        ];
    }

    private function present(Collection $c, bool $full = false): array
    {
        $out = [
            'id' => $c->id,
            'date' => $c->date->toDateString(),
            'title' => $c->title,
            'status' => $c->status,
            'status_label' => Collection::STATUSES[$c->status],
            'cash_total' => (float) $c->cash_total,
            'mpesa_total' => (float) $c->mpesa_total,
            'total' => (float) $c->total,
            'kinds' => $c->lines->map(fn ($l) => ['label' => $l->label, 'cash' => (float) $l->cash_amount, 'mpesa' => (float) $l->mpesa_amount])->values(),
            'counted_by' => $c->counter?->full_name,
            'counted_by_id' => $c->counted_by,
            'confirmed_by' => $c->confirmer?->full_name,
            'confirmed_at' => $c->confirmed_at?->toIso8601String(),
            'return_reason' => $c->return_reason,
            'journal' => $c->journal ? ['id' => $c->journal->id, 'number' => $c->journal->number] : null,
            'banked' => $c->bankingJournal ? ['id' => $c->bankingJournal->id, 'number' => $c->bankingJournal->number, 'date' => $c->bankingJournal->date->toDateString()] : null,
            'witnesses' => $c->witnesses ?? [],
        ];
        if ($full) {
            $out += [
                'lines' => $c->lines->map(fn ($l) => [
                    'label' => $l->label, 'account' => ['id' => $l->account->id, 'code' => $l->account->code, 'name' => $l->account->name],
                    'fund' => $l->fund ? ['id' => $l->fund->id, 'code' => $l->fund->code, 'name' => $l->fund->name] : null,
                    'cash' => (float) $l->cash_amount, 'mpesa' => (float) $l->mpesa_amount,
                ])->values(),
                'denominations' => $c->denominations,
                'cash_account' => ['id' => $c->cashAccount->id, 'name' => $c->cashAccount->name, 'kind' => $c->cashAccount->cash_kind],
                'mpesa_account' => $c->mpesaAccount ? ['id' => $c->mpesaAccount->id, 'name' => $c->mpesaAccount->name] : null,
                'attendance' => $c->attendance ? ['id' => $c->attendance->id, 'name' => $c->attendance->event_name ?: ($c->attendance->gatheringType?->name ?? 'Service')] : null,
                'attendance_record_id' => $c->attendance_record_id,
                'gathering_type_id' => $c->gathering_type_id,
                'notes' => $c->notes,
            ];
        }

        return $out;
    }

    /** Giving per month for the last 12 months (confirmed only), for the cards. */
    private function monthly(Territory $place): array
    {
        $start = now()->startOfMonth()->subMonths(11);
        $rows = Collection::where('territory_id', $place->id)->where('status', 'posted')->where('date', '>=', $start->toDateString())
            ->selectRaw("DATE_FORMAT(date, '%Y-%m') AS ym, SUM(total) AS total")->groupBy('ym')->pluck('total', 'ym');
        $out = ['labels' => [], 'data' => []];
        for ($m = $start->copy(); $m->lte(now()); $m->addMonth()) {
            $out['labels'][] = $m->format('M');
            $out['data'][] = round((float) ($rows[$m->format('Y-m')] ?? 0), 2);
        }

        return $out;
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'date' => ['required', 'date'],
            'title' => ['nullable', 'string', 'max:150'],
            'attendance_record_id' => ['nullable', 'integer'],
            'gathering_type_id' => ['nullable', 'integer'],
            'cash_account_id' => ['nullable', 'integer'],
            'mpesa_account_id' => ['nullable', 'integer'],
            'denominations' => ['nullable', 'array'],
            'denominations.*' => ['nullable', 'integer', 'min:0'],
            'witnesses' => ['nullable', 'array', 'max:6'],
            'witnesses.*' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1', 'max:20'],
            'lines.*.label' => ['nullable', 'string', 'max:100'],
            'lines.*.account_id' => ['required', 'integer'],
            'lines.*.fund_id' => ['nullable', 'integer'],
            'lines.*.cash_amount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.mpesa_amount' => ['nullable', 'numeric', 'min:0'],
        ], ['lines.required' => 'Enter what was collected.']);
    }
}
