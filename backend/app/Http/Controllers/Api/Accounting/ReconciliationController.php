<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Models\BankReconciliation;
use App\Models\BankStatementLine;
use App\Models\CashCount;
use App\Models\Journal;
use App\Models\Territory;
use App\Models\User;
use App\Services\Accounting\Board;
use App\Services\Accounting\Books;
use App\Services\Accounting\CashCounts;
use App\Services\Accounting\Chart;
use App\Services\Accounting\Ledger;
use App\Services\Accounting\PettyCash;
use App\Services\Accounting\Reconciliations;
use App\Support\AccountingAccess;
use App\Support\PlaceAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Proving the books right (docs/specs/accounting-spec.md, A2): cash counts,
 * bank and M-Pesa reconciliations with their statements, petty cash on its
 * float, and the board of the places below. Approving a difference or
 * signing off a reconciliation is always someone other than who did it.
 */
class ReconciliationController extends AccountingBase
{
    public function __construct(
        private CashCounts $counts,
        private Reconciliations $recs,
        private PettyCash $petty,
        private Board $board,
        private Books $books,
        private Chart $chart,
        private Ledger $ledger,
    ) {}

    /** GET /accounting/reconciliation - each money account's last check, what waits, the history. */
    public function index(Request $request): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $me = $request->user()->id;
        $can = AccountingAccess::abilities($request->user(), $place);
        $latestCounts = $this->counts->latest($place);
        $latestRecs = BankReconciliation::where('territory_id', $place->id)->orderByDesc('statement_date')->orderByDesc('id')->get()->unique('account_id')->keyBy('account_id');
        $lastMonthEnd = CarbonImmutable::today()->startOfMonth()->subDay();
        $accounts = collect($this->books->cashPosition($place))->map(function ($a) use ($latestCounts, $latestRecs, $lastMonthEnd) {
            $counted = in_array($a['cash_kind'], ['cash', 'petty_cash'], true);
            $last = $counted ? ($latestCounts[$a['id']] ?? null) : ($latestRecs[$a['id']] ?? null);
            $lastOn = $last ? ($counted ? $last->counted_on : $last->statement_date) : null;
            $done = $last && in_array($last->status, $counted ? ['balanced', 'approved'] : ['approved'], true);

            return $a + [
                'check' => $counted ? 'count' : 'reconcile',
                'last' => $last ? ['id' => $last->id, 'on' => $lastOn->toDateString(), 'status' => $last->status, 'difference' => (float) $last->difference] : null,
                'due' => ! $done || $lastOn->lt($lastMonthEnd->startOfMonth()),
            ];
        })->values();

        $waitingCounts = CashCount::with(['account', 'counter'])->where('territory_id', $place->id)->where('status', 'waiting')->orderBy('counted_on')->get();
        $waitingRecs = BankReconciliation::with(['account', 'preparer'])->where('territory_id', $place->id)->where('status', 'submitted')->orderBy('statement_date')->get();
        $history = CashCount::with(['account', 'counter', 'approver'])->where('territory_id', $place->id)->orderByDesc('counted_on')->orderByDesc('id')->limit(60)->get()
            ->toBase()->map(fn ($c) => $this->presentCount($c, $me))
            ->merge(BankReconciliation::with(['account', 'preparer', 'approver'])->where('territory_id', $place->id)->orderByDesc('statement_date')->limit(60)->get()->map(fn ($r) => $this->presentRec($r, $me)))
            ->sortByDesc('date')->values();

        return $this->ok([
            'place' => $this->placeInfo($place),
            'can' => $can,
            'me' => $me,
            'accounts' => $accounts,
            'waiting' => $waitingCounts->toBase()->map(fn ($c) => $this->presentCount($c, $me))->merge($waitingRecs->map(fn ($r) => $this->presentRec($r, $me)))->values(),
            'history' => $history,
            'petty' => $this->petty->status($place),
            'denominations' => CashCount::DENOMINATIONS,
        ]);
    }

    /** GET /accounting/reconciliation-board - the places below and how up to date their books are. */
    public function board(Request $request): JsonResponse
    {
        $acting = PlaceAccess::acting($request->user());
        if (! $acting || ! AccountingAccess::abilities($request->user(), $acting)['below']) {
            return $this->forbidden('The board shows the places below a region or the diocese.');
        }

        return $this->ok(['place' => $this->placeInfo($acting), 'rows' => $this->board->for(PlaceAccess::descendantIds($acting))]);
    }

    // ------------------------------------------------------------ cash counts

    /** POST /accounting/cash-counts */
    public function count(Request $request): JsonResponse
    {
        $place = $this->place($request, 'reconcile');
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $data = $request->validate([
            'account_id' => ['required', 'integer'],
            'counted_on' => ['required', 'date'],
            'denominations' => ['nullable', 'array'],
            'denominations.*' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'counted_total' => ['nullable', 'numeric', 'min:0'],
            'reason' => ['nullable', 'string', 'max:255'],
            'is_surprise' => ['nullable', 'boolean'],
        ]);
        $c = $this->counts->count($place, $request->user(), $data);

        return $this->ok($this->presentCount($c->load('account', 'counter'), $request->user()->id), $c->status === 'balanced' ? 'Counted - it agrees with the book.' : 'Counted - the difference waits for someone else to approve.', 201);
    }

    /** POST /accounting/cash-counts/{id}/approve */
    public function approveCount(Request $request, int $id): JsonResponse
    {
        [$c, , $deny] = $this->countFor($request, $id, 'authorise');
        if ($deny) {
            return $deny;
        }
        $c = $this->counts->approve($c, $request->user());

        return $this->ok($this->presentCount($c->load('account', 'counter', 'approver', 'journal'), $request->user()->id), 'Approved - the difference is in the books.');
    }

    /** POST /accounting/cash-counts/{id}/reject {reason} */
    public function rejectCount(Request $request, int $id): JsonResponse
    {
        [$c, , $deny] = $this->countFor($request, $id, 'authorise');
        if ($deny) {
            return $deny;
        }
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], ['reason.required' => 'Say why it should be counted again.']);
        $c = $this->counts->reject($c, $request->user(), $data['reason']);

        return $this->ok($this->presentCount($c->load('account', 'counter', 'approver'), $request->user()->id), 'Sent back to be counted again.');
    }

    // ------------------------------------------------------------ reconciliations

    /** POST /accounting/reconciliations {account_id, statement_date, statement_balance} */
    public function start(Request $request): JsonResponse
    {
        $place = $this->place($request, 'reconcile');
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $data = $request->validate(['account_id' => ['required', 'integer'], 'statement_date' => ['required', 'date'], 'statement_balance' => ['required', 'numeric']]);

        return $this->ok($this->full($request, $this->recs->start($place, $request->user(), $data)), 'Reconciliation started.', 201);
    }

    /** GET /accounting/reconciliations/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        [$r, , $deny] = $this->recFor($request, $id);

        return $deny ?? $this->ok($this->full($request, $r));
    }

    /** PUT /accounting/reconciliations/{id} {statement_date, statement_balance, notes} */
    public function update(Request $request, int $id): JsonResponse
    {
        [$r, , $deny] = $this->recFor($request, $id, 'reconcile');
        if ($deny) {
            return $deny;
        }
        $data = $request->validate(['statement_date' => ['sometimes', 'date'], 'statement_balance' => ['sometimes', 'numeric'], 'notes' => ['nullable', 'string', 'max:255']]);

        return $this->ok($this->full($request, $this->recs->update($r, $data)), 'Saved.');
    }

    /** POST /accounting/reconciliations/{id}/tick {line_ids[], cleared} */
    public function tick(Request $request, int $id): JsonResponse
    {
        [$r, , $deny] = $this->recFor($request, $id, 'reconcile');
        if ($deny) {
            return $deny;
        }
        $data = $request->validate(['line_ids' => ['required', 'array', 'min:1', 'max:2000'], 'line_ids.*' => ['integer'], 'cleared' => ['required', 'boolean']]);

        return $this->ok($this->full($request, $this->recs->tick($r, array_map('intval', $data['line_ids']), (bool) $data['cleared'])));
    }

    /** POST /accounting/reconciliations/{id}/statement {rows[], mapping?} - an imported statement, then auto-match. */
    public function import(Request $request, int $id): JsonResponse
    {
        [$r, , $deny] = $this->recFor($request, $id, 'reconcile');
        if ($deny) {
            return $deny;
        }
        $data = $request->validate([
            'rows' => ['required', 'array', 'min:1', 'max:2000'],
            'rows.*.date' => ['required', 'date'],
            'rows.*.description' => ['nullable', 'string', 'max:500'],
            'rows.*.reference' => ['nullable', 'string', 'max:100'],
            'rows.*.money_in' => ['nullable', 'numeric'],
            'rows.*.money_out' => ['nullable', 'numeric'],
            'rows.*.balance' => ['nullable', 'numeric'],
            'mapping' => ['nullable', 'array'],
        ], ['rows.*.date.date' => 'A row has a date that can\'t be read - check the date column.']);
        $r = $this->recs->import($r, $data['rows'], $data['mapping'] ?? null);
        $present = $this->full($request, $r);
        $matched = collect($present['statement'])->where('status', 'matched')->count();

        return $this->ok($present, count($present['statement'])." statement lines - {$matched} matched to the books.");
    }

    /** POST /accounting/reconciliations/{id}/statement/{line}/{action} - match {line_id} | unmatch | ignore {ignore} | add {account_id, ...} */
    public function statementLine(Request $request, int $id, int $line, string $action): JsonResponse
    {
        [$r, , $deny] = $this->recFor($request, $id, 'reconcile');
        if ($deny) {
            return $deny;
        }
        $s = BankStatementLine::where('reconciliation_id', $r->id)->find($line);
        if (! $s) {
            return $this->notFound('That line isn\'t on this statement.');
        }
        $r = match ($action) {
            'match' => $this->recs->match($r, $s, (int) $request->validate(['line_id' => ['required', 'integer']])['line_id']),
            'unmatch' => $this->recs->unmatch($r, $s),
            'ignore' => $this->recs->ignore($r, $s, (bool) $request->input('ignore', true)),
            'add' => $this->recs->addToBooks($r, $s, $request->user(), $request->validate([
                'account_id' => ['required', 'integer'], 'budget_line_id' => ['nullable', 'integer'], 'fund_id' => ['nullable', 'integer'],
                'narration' => ['nullable', 'string', 'max:255'], 'party_name' => ['nullable', 'string', 'max:150'],
            ])),
            default => null,
        };
        if (! $r) {
            return $this->notFound('Unknown action.');
        }

        return $this->ok($this->full($request, $r), $action === 'add' ? 'Added to the books and matched.' : 'Saved.');
    }

    /** POST /accounting/reconciliations/{id}/submit */
    public function submit(Request $request, int $id): JsonResponse
    {
        [$r, , $deny] = $this->recFor($request, $id, 'reconcile');

        return $deny ?? $this->ok($this->full($request, $this->recs->submit($r, $request->user())), 'Submitted - someone else now signs it off.');
    }

    /** POST /accounting/reconciliations/{id}/approve */
    public function approve(Request $request, int $id): JsonResponse
    {
        [$r, , $deny] = $this->recFor($request, $id, 'authorise');

        return $deny ?? $this->ok($this->full($request, $this->recs->approve($r, $request->user())), 'Signed off.');
    }

    /** POST /accounting/reconciliations/{id}/return {reason} */
    public function sendBack(Request $request, int $id): JsonResponse
    {
        [$r, , $deny] = $this->recFor($request, $id, 'authorise');
        if ($deny) {
            return $deny;
        }
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], ['reason.required' => 'Say what needs looking at.']);

        return $this->ok($this->full($request, $this->recs->sendBack($r, $request->user(), $data['reason'])), 'Sent back.');
    }

    /** DELETE /accounting/reconciliations/{id} - throw away one still being prepared. */
    public function discard(Request $request, int $id): JsonResponse
    {
        [$r, , $deny] = $this->recFor($request, $id, 'reconcile');
        if ($deny) {
            return $deny;
        }
        $this->recs->discard($r);

        return $this->ok(null, 'Discarded.');
    }

    // ------------------------------------------------------------ petty cash

    /** GET /accounting/petty-cash - the float, who keeps it, what to top up; the people who can keep it. */
    public function petty(Request $request): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $people = User::whereHas('activeAssignments', fn ($q) => $q->where('territory_id', $place->id))->orderBy('firstname')->get()
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->full_name])->values();

        return $this->ok($this->petty->status($place) + ['people' => $people, 'can' => AccountingAccess::abilities($request->user(), $place)]);
    }

    /** PUT /accounting/petty-cash {imprest_float, custodian_id} */
    public function setFloat(Request $request): JsonResponse
    {
        $place = $this->place($request, 'accounts');
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $data = $request->validate(['imprest_float' => ['required', 'numeric', 'min:1', 'max:10000000'], 'custodian_id' => ['nullable', 'integer', 'exists:users,id']]);
        $this->petty->setFloat($place, $this->petty->account($place), (float) $data['imprest_float'], $data['custodian_id'] ?? null);

        return $this->ok($this->petty->status($place), 'The petty cash float is set.');
    }

    /** POST /accounting/petty-cash/spend - a petty cash voucher. */
    public function spend(Request $request): JsonResponse
    {
        $place = $this->place($request, 'petty');
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $data = $request->validate([
            'date' => ['required', 'date'], 'payee' => ['required', 'string', 'max:150'], 'narration' => ['nullable', 'string', 'max:255'], 'account_id' => ['nullable', 'integer'],
        ] + $this->lineRules(), ['payee.required' => 'Who was paid?']);
        $j = $this->petty->spend($place, $request->user(), $data);

        return $this->ok($this->books->presentJournal($j->fresh(), true), "Petty cash voucher {$j->number} written.", 201);
    }

    /** POST /accounting/petty-cash/top-up {from_account_id} - prepares the payment voucher. */
    public function topUp(Request $request): JsonResponse
    {
        $place = $this->place($request, 'prepare');
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $data = $request->validate(['from_account_id' => ['required', 'integer']]);
        $pv = $this->petty->topUp($place, $request->user(), (int) $data['from_account_id']);

        return $this->ok(['voucher' => $this->books->presentVoucher($pv->load('lines.account', 'payFrom')), 'petty' => $this->petty->status($place)], "Top-up {$pv->number} prepared - it now needs authorising.", 201);
    }

    // ------------------------------------------------------------ helpers

    /** @return array{0: ?CashCount, 1: ?Territory, 2: ?JsonResponse} */
    private function countFor(Request $request, int $id, string $ability): array
    {
        $c = CashCount::find($id);
        $place = $c ? Territory::find($c->territory_id) : null;
        if (! $c || ! $place || ! AccountingAccess::canRead($request->user(), $place)) {
            return [null, null, $this->notFound('That count isn\'t in the books.')];
        }
        if (! AccountingAccess::can($request->user(), $place, $ability)) {
            return [null, null, $this->forbidden('Your role can\'t approve cash differences here.')];
        }

        return [$c, $place, null];
    }

    /** @return array{0: ?BankReconciliation, 1: ?Territory, 2: ?JsonResponse} */
    private function recFor(Request $request, int $id, ?string $ability = null): array
    {
        $r = BankReconciliation::find($id);
        $place = $r ? Territory::find($r->territory_id) : null;
        if (! $r || ! $place || ! AccountingAccess::canRead($request->user(), $place)) {
            return [null, null, $this->notFound('That reconciliation isn\'t in the books.')];
        }
        if ($ability && ! AccountingAccess::can($request->user(), $place, $ability)) {
            return [null, null, $this->forbidden($ability === 'authorise' ? 'Your role can\'t sign off reconciliations here.' : 'Your role can\'t reconcile here.')];
        }

        return [$r, $place, null];
    }

    private function full(Request $request, BankReconciliation $r): array
    {
        $place = Territory::findOrFail($r->territory_id);
        $can = AccountingAccess::abilities($request->user(), $place);
        $me = $request->user()->id;

        return $this->recs->present($r) + [
            'place' => $this->placeInfo($place),
            'can' => $can + [
                'edit' => $can['reconcile'] && $r->isOpen(),
                'approve_this' => $can['authorise'] && $r->status === 'submitted' && (int) $r->prepared_by !== $me,
                'prepared_this' => (int) $r->prepared_by === $me,
            ],
        ];
    }

    private function presentCount(CashCount $c, int $me): array
    {
        return [
            'type' => 'count',
            'id' => $c->id,
            'date' => $c->counted_on->toDateString(),
            'account' => ['id' => $c->account->id, 'name' => $c->account->name, 'kind' => $c->account->cash_kind],
            'counted' => (float) $c->counted_total,
            'book' => (float) $c->book_balance,
            'difference' => (float) $c->difference,
            'denominations' => $c->denominations,
            'reason' => $c->reason,
            'surprise' => $c->is_surprise,
            'status' => $c->status,
            'status_label' => CashCount::STATUSES[$c->status],
            'by' => $c->counter?->full_name,
            'approved_by' => $c->approver?->full_name,
            'reject_reason' => $c->reject_reason,
            'journal' => $c->journal_id ? ['id' => $c->journal_id, 'number' => Journal::whereKey($c->journal_id)->value('number')] : null,
            'mine' => (int) $c->counted_by === $me,
        ];
    }

    private function presentRec(BankReconciliation $r, int $me): array
    {
        return [
            'type' => 'reconciliation',
            'id' => $r->id,
            'date' => $r->statement_date->toDateString(),
            'account' => ['id' => $r->account->id, 'name' => $r->account->name, 'kind' => $r->account->cash_kind],
            'statement_balance' => (float) $r->statement_balance,
            'book' => (float) $r->book_balance,
            'difference' => (float) $r->difference,
            'status' => $r->status,
            'status_label' => BankReconciliation::STATUSES[$r->status],
            'by' => $r->preparer?->full_name,
            'approved_by' => $r->approver?->full_name,
            'return_reason' => $r->return_reason,
            'mine' => (int) $r->prepared_by === $me,
        ];
    }
}
