<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Approval\Services\ApprovalService;
use App\Approval\Services\Inbox;
use App\Models\AccountingFund;
use App\Models\Requisition;
use App\Models\StaffAdvance;
use App\Models\Territory;
use App\Services\Accounting\Books;
use App\Services\Accounting\Chart;
use App\Services\Accounting\Requisitions;
use App\Services\Accounting\StaffAdvances;
use App\Services\Budgets\BudgetBook;
use App\Support\AccountingAccess;
use App\Support\PlaceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requisitions and staff advances (docs/specs/accounting-spec.md, A4).
 * Anyone who may ask sees their own; whoever reads the books sees all of the
 * place's; the treasurer makes the payment once it is approved.
 */
class RequisitionController extends AccountingBase
{
    public function __construct(private Requisitions $requisitions, private StaffAdvances $advances, private ApprovalService $engine, private Inbox $inbox, private Chart $chart, private Books $books) {}

    /** GET /accounting/requisitions */
    public function index(Request $request): JsonResponse
    {
        $place = $this->placeFor($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $user = $request->user();
        $all = AccountingAccess::canRead($user, $place);
        $items = Requisition::with(['requester', 'decider', 'voucher:id,number,status', 'account:id,code,name'])
            ->where('territory_id', $place->id)->when(! $all, fn ($q) => $q->where('requested_by', $user->id))
            ->orderByDesc('id')->limit(500)->get();
        $advances = StaffAdvance::where('territory_id', $place->id)->when(! $all, fn ($q) => $q->where('user_id', $user->id))->orderByDesc('id')->limit(300)->get();

        return $this->ok([
            'place' => $this->placeInfo($place),
            'can' => $this->can($request, $place),
            'me' => $user->id,
            'all' => $all,
            'items' => $items->map(fn ($r) => $this->present($r, $user))->values(),
            'advances' => $advances->map(fn ($a) => $this->presentAdvance($a))->values(),
        ]);
    }

    /** GET /accounting/requisitions/options - what the Ask window offers, with what is left on each budget line. */
    public function options(Request $request): JsonResponse
    {
        $place = $this->placeFor($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $usable = $this->chart->usable($place);
        $budget = app(BudgetBook::class)->budgetInUseOn($place->territory_type->value, $place->id, now()->toDateString());
        $left = $budget ? \App\Models\BudgetLineItem::where('budget_id', $budget->id)->get()->mapWithKeys(fn ($i) => [$i->budget_line_id => round((float) $i->budgeted_amount - (float) $i->actual_amount, 2)]) : collect();
        $lines = \App\Models\BudgetLine::where('is_active', true)->forPlace($place->territory_type->value, $place->id)->get()->keyBy('account_id');

        return $this->ok([
            'accounts' => $usable->filter(fn ($a) => ! $a->cash_kind && in_array($a->type, ['expense', 'asset', 'liability'], true) && $a->system_key !== 'staff_advances')
                ->sortBy(fn ($a) => ($a->type === 'expense' ? '0' : '1').$a->code)
                ->map(fn ($a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'type' => $a->type,
                    'left' => isset($lines[$a->id]) && $left->has($lines[$a->id]->id) ? $left[$lines[$a->id]->id] : null])->values(),
            'cash' => $usable->filter(fn ($a) => $a->cash_kind)->map(fn ($a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'cash_kind' => $a->cash_kind])->values(),
            'funds' => AccountingFund::where('is_active', true)->orderBy('display_order')->get(['id', 'code', 'name', 'is_restricted']),
            'kinds' => collect(Requisition::KINDS)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values(),
            'budget' => $budget ? ['id' => $budget->id, 'label' => $budget->period_label] : null,
            'overdue_advance' => StaffAdvance::where('user_id', $request->user()->id)->where('status', 'open')->where('due_on', '<', now()->toDateString())->exists(),
            'suppliers' => \App\Models\Supplier::where('territory_id', $place->id)->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'today' => now()->toDateString(),
        ]);
    }

    /** GET /accounting/requisitions/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        [$r, , $deny] = $this->requisition($request, $id);

        return $deny ?? $this->ok($this->present($r, $request->user(), true));
    }

    /** POST /accounting/requisitions */
    public function store(Request $request): JsonResponse
    {
        $place = $this->placeFor($request, 'request');
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $r = $this->requisitions->create($place, $request->user(), $this->validated($request));

        $who = $r->status === 'approved' ? null : app(\App\Approval\Services\Handover::class)->sentence($r);

        return $this->ok($this->present($r, $request->user(), true), $r->status === 'approved' ? "{$r->number} approved." : "{$r->number} sent for approval".($who ? " - {$who}." : '.'), 201);
    }

    /** PUT /accounting/requisitions/{id} - the person who asked fixes it; it goes for approval again. */
    public function update(Request $request, int $id): JsonResponse
    {
        [$r, , $deny] = $this->requisition($request, $id);
        if ($deny) {
            return $deny;
        }
        $r = $this->requisitions->update($r, $request->user(), $this->validated($request));

        $who = app(\App\Approval\Services\Handover::class)->sentence($r);

        return $this->ok($this->present($r, $request->user(), true), 'Sent for approval again'.($who ? " - {$who}." : '.'));
    }

    /** POST /accounting/requisitions/{id}/{approve|reject|return} {comment} */
    public function decide(Request $request, int $id, string $decision): JsonResponse
    {
        [$r, , $deny] = $this->requisition($request, $id);
        if ($deny) {
            return $deny;
        }
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:500']]);
        $r = $this->requisitions->decide($r, $request->user(), $decision, $data['comment'] ?? null);

        return $this->ok($this->present($r, $request->user(), true), ['approve' => $r->status === 'approved' ? 'Approved - the treasurer can pay it.' : 'Approved - it moves to the next stage.', 'reject' => 'Rejected.', 'return' => 'Sent back for changes.'][$decision]);
    }

    /** POST /accounting/requisitions/{id}/cancel */
    public function cancel(Request $request, int $id): JsonResponse
    {
        [$r, $place, $deny] = $this->requisition($request, $id);
        if ($deny) {
            return $deny;
        }
        if ((int) $r->requested_by !== (int) $request->user()->id && ! AccountingAccess::can($request->user(), $place, 'prepare')) {
            return $this->forbidden('Only the person who asked, or the treasurer, can cancel it.');
        }

        return $this->ok($this->present($this->requisitions->cancel($r, $request->user()), $request->user(), true), 'Cancelled.');
    }

    /** POST /accounting/requisitions/{id}/pay {pay_from_account_id} - makes the (already authorised) payment voucher. */
    public function pay(Request $request, int $id): JsonResponse
    {
        [$r, $place, $deny] = $this->requisition($request, $id);
        if ($deny) {
            return $deny;
        }
        if (! AccountingAccess::can($request->user(), $place, 'prepare')) {
            return $this->forbidden('Your role can\'t make payments here.');
        }
        $data = $request->validate(['pay_from_account_id' => ['required', 'integer']]);
        $pv = $this->requisitions->makePayment($r, $request->user(), (int) $data['pay_from_account_id']);

        return $this->ok(['requisition' => $this->present($r->fresh(), $request->user(), true), 'voucher_id' => $pv->id, 'voucher_number' => $pv->number], "Voucher {$pv->number} is ready to pay - it is already authorised.", 201);
    }

    /** POST · DELETE /accounting/requisitions/{id}/attachments - quotes, invoices. */
    public function addAttachment(Request $request, int $id): JsonResponse
    {
        [$r, , $deny] = $this->requisition($request, $id);
        if ($deny) {
            return $deny;
        }
        if ((int) $r->requested_by !== (int) $request->user()->id && ! AccountingAccess::can($request->user(), Territory::find($r->territory_id), 'prepare')) {
            return $this->forbidden('Only the person who asked, or the treasurer, adds papers to it.');
        }
        $request->validate(['file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120']], ['file.max' => 'The file must be 5 MB or smaller.']);
        if ($r->getMedia('attachments')->count() >= 5) {
            throw ValidationException::withMessages(['file' => ['A requisition can hold 5 files.']]);
        }
        $f = $request->file('file');
        $r->addMedia($f)->usingFileName(Str::uuid().'.'.strtolower($f->getClientOriginalExtension() ?: 'bin'))->usingName(pathinfo($f->getClientOriginalName(), PATHINFO_FILENAME) ?: 'Quote')->toMediaCollection('attachments');

        return $this->ok($this->present($r->fresh(), $request->user(), true), 'Attached.', 201);
    }

    public function showAttachment(Request $request, int $id, int $media): Response|JsonResponse
    {
        [$r, , $deny] = $this->requisition($request, $id);
        if ($deny) {
            return $deny;
        }
        $file = $r->getMedia('attachments')->firstWhere('id', $media);

        return $file ? response()->file($file->getPath(), ['Content-Type' => $file->mime_type]) : $this->notFound('That file isn\'t on this requisition.');
    }

    public function removeAttachment(Request $request, int $id, int $media): JsonResponse
    {
        [$r, , $deny] = $this->requisition($request, $id);
        if ($deny) {
            return $deny;
        }
        if ((int) $r->requested_by !== (int) $request->user()->id || ! in_array($r->status, ['submitted', 'returned'], true)) {
            return $this->forbidden('Papers stay on a requisition once it is decided.');
        }
        $r->getMedia('attachments')->firstWhere('id', $media)?->delete();

        return $this->ok($this->present($r->fresh(), $request->user(), true), 'Removed.');
    }

    /** POST /accounting/advances/{id}/retire */
    public function retire(Request $request, int $id): JsonResponse
    {
        $a = StaffAdvance::find($id);
        $place = $a ? Territory::find($a->territory_id) : null;
        if (! $a || ! $place || ! AccountingAccess::can($request->user(), $place, 'pay')) {
            return $this->forbidden('The treasurer records what an advance was spent on.');
        }
        $data = $request->validate([
            'date' => ['required', 'date'],
            'lines' => ['nullable', 'array', 'max:20'],
            'lines.*.account_id' => ['required', 'integer'],
            'lines.*.amount' => ['required', 'numeric', 'min:0'],
            'lines.*.fund_id' => ['nullable', 'integer'],
            'lines.*.memo' => ['nullable', 'string', 'max:255'],
            'returned' => ['nullable', 'numeric', 'min:0'],
            'return_account_id' => ['nullable', 'integer'],
        ]);
        $a = $this->advances->retire($a, $request->user(), $data);

        return $this->ok($this->presentAdvance($a), $a->status === 'retired' ? 'Accounted for in full.' : 'Recorded - KES '.number_format($a->outstanding(), 2).' still to account for.');
    }

    // ------------------------------------------------------------------ helpers

    private function placeFor(Request $request, ?string $ability = null): Territory|JsonResponse
    {
        $id = $request->input('territory_id', $request->query('territory_id'));
        $place = PlaceAccess::place($request->user(), $id !== null && ctype_digit((string) $id) ? (int) $id : null);
        if (! $place || ! AccountingAccess::canSeeRequisitions($request->user(), $place)) {
            return $this->forbidden('These requisitions aren\'t yours to see.');
        }
        if ($ability && ! AccountingAccess::can($request->user(), $place, $ability)) {
            return $this->forbidden('Your role can\'t ask for money here.');
        }

        return $place;
    }

    /** @return array{0: ?Requisition, 1: ?Territory, 2: ?JsonResponse} */
    private function requisition(Request $request, int $id): array
    {
        $r = Requisition::find($id);
        $place = $r ? Territory::find($r->territory_id) : null;
        $user = $request->user();
        $ok = $r && $place && ((int) $r->requested_by === (int) $user->id || AccountingAccess::canRead($user, $place)
            || ($this->engine->latest($r) && $this->inbox->canView($user, $this->engine->latest($r))));

        return $ok ? [$r, $place, null] : [null, null, $this->notFound('That requisition isn\'t yours to see.')];
    }

    private function can(Request $request, Territory $place): array
    {
        $a = AccountingAccess::abilities($request->user(), $place);

        return ['request' => $a['request'], 'pay' => $a['prepare'], 'retire' => $a['pay'], 'books' => $a['read'], 'authorise' => $a['authorise'], 'own' => $a['own']];
    }

    private function present(Requisition $r, $user, bool $full = false): array
    {
        $r->loadMissing(['requester', 'decider', 'voucher', 'account', 'budgetLine', 'fund']);
        $req = $this->engine->latest($r);
        $turn = $req ? $this->inbox->myTurn($user, $req) : null;
        $place = Territory::find($r->territory_id);
        $legacy = ! $req && $r->status === 'submitted' && AccountingAccess::can($user, $place, 'authorise') && (int) $r->requested_by !== (int) $user->id;
        $out = [
            'id' => $r->id,
            'number' => $r->number,
            'kind' => $r->kind,
            'kind_label' => Requisition::KINDS[$r->kind],
            'purpose' => $r->purpose,
            'amount' => (float) $r->amount,
            'needed_by' => $r->needed_by?->toDateString(),
            'status' => $r->status,
            'status_label' => Requisition::STATUSES[$r->status],
            'requested_by' => $r->requester?->full_name,
            'requested_by_id' => $r->requested_by,
            'requested_at' => $r->created_at?->toIso8601String(),
            'decided_by' => $r->decider?->full_name,
            'decision_note' => $r->decision_note,
            'payee_name' => $r->payee_name,
            'account' => $r->account ? ['id' => $r->account->id, 'code' => $r->account->code, 'name' => $r->account->name] : null,
            'voucher' => $r->voucher ? ['id' => $r->voucher->id, 'number' => $r->voucher->number, 'status' => $r->voucher->status] : null,
            'waiting_on' => $req && $req->status === 'pending' ? $this->inbox->present($req, $user)['waiting_on'] : [],
            'files' => $r->getMedia('attachments')->count(),
            'can' => [
                'decide' => (bool) $turn || $legacy,
                'edit' => (int) $r->requested_by === (int) $user->id && in_array($r->status, ['submitted', 'returned'], true),
                'pay' => $r->status === 'approved' && ! $r->payment_voucher_id && ! \App\Services\Accounting\Procurement::mustOrder($r) && AccountingAccess::can($user, $place, 'prepare'),
                'cancel' => in_array($r->status, ['submitted', 'returned', 'approved'], true) && ! $r->payment_voucher_id
                    && ((int) $r->requested_by === (int) $user->id || AccountingAccess::can($user, $place, 'prepare')),
            ],
        ];
        if ($full) {
            $out += [
                'payee_phone' => $r->payee_phone,
                'payee' => $r->payee, 'payee_text' => \App\Support\PayTo::describe($r->payee),
                'budget_line' => $r->budgetLine?->name,
                'fund' => $r->fund ? ['id' => $r->fund->id, 'name' => $r->fund->name] : null,
                'budget_line_id' => $r->budget_line_id,
                'fund_id' => $r->fund_id,
                'account_id' => $r->account_id,
                'attachments' => $r->getMedia('attachments')->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'mime' => $m->mime_type])->values(),
                'approval' => $req ? $this->inbox->present($req, $user, true) : null,
                'procurement' => ProcurementController::quotesBlock($r, $user),
                'advance' => $r->kind === 'advance' ? (StaffAdvance::where('requisition_id', $r->id)->first() ? $this->presentAdvance(StaffAdvance::where('requisition_id', $r->id)->first()) : null) : null,
            ];
        }

        return $out;
    }

    private function presentAdvance(StaffAdvance $a): array
    {
        return [
            'id' => $a->id, 'holder' => $a->holder_name, 'user_id' => $a->user_id, 'purpose' => $a->purpose, 'amount' => (float) $a->amount,
            'issued_on' => $a->issued_on->toDateString(), 'due_on' => $a->due_on->toDateString(), 'spent' => (float) $a->spent, 'returned' => (float) $a->returned,
            'outstanding' => $a->outstanding(), 'status' => $a->status, 'overdue' => $a->isOverdue(), 'requisition_id' => $a->requisition_id,
        ];
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'kind' => ['required', 'in:payment,purchase,advance'],
            'purpose' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:1'],
            'needed_by' => ['nullable', 'date'],
            'account_id' => ['nullable', 'integer'],
            'budget_line_id' => ['nullable', 'integer'],
            'fund_id' => ['nullable', 'integer'],
            'payee_name' => ['nullable', 'string', 'max:150'],
            'payee_phone' => ['nullable', 'string', 'max:30'],
            ...\App\Support\PayTo::rules(),
        ], ['purpose.required' => 'Say what the money is for.', 'amount.required' => 'How much is needed?']);
    }
}
