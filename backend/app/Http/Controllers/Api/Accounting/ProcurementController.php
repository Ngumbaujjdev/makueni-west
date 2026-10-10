<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Approval\Services\ApprovalService;
use App\Approval\Services\Inbox;
use App\Models\GoodsReceived;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\Requisition;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\Territory;
use App\Services\Accounting\Chart;
use App\Services\Accounting\Procurement;
use App\Support\AccountingAccess;
use App\Support\PlaceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Procurement (docs/specs/accounting-spec.md, A5): suppliers, quotations on
 * a purchase requisition, the order, goods received and the supplier's bill.
 * Whoever reads the books sees it; whoever buys (procurement.manage) works
 * in it; paying a bill makes a voucher, so it needs payments.prepare.
 */
class ProcurementController extends AccountingBase
{
    public function __construct(private Procurement $procurement, private Chart $chart, private ApprovalService $engine, private Inbox $inbox) {}

    /** GET /accounting/procurement - what to order, the orders, the bills and the suppliers. */
    public function index(Request $request): JsonResponse
    {
        $place = $this->placeFor($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $user = $request->user();
        $toOrder = Requisition::with(['requester', 'quotations'])->where('territory_id', $place->id)->where('kind', 'purchase')->where('status', 'approved')->whereNull('payment_voucher_id')->orderBy('id')->get();
        $orders = PurchaseOrder::with(['supplier', 'lines', 'requisition:id,number,purpose'])->where('territory_id', $place->id)->orderByDesc('id')->limit(500)->get();
        $bills = SupplierInvoice::with(['supplier', 'voucher:id,number,status', 'order:id,number'])->where('territory_id', $place->id)->orderByDesc('id')->limit(500)->get();

        return $this->ok([
            'place' => $this->placeInfo($place),
            'can' => $this->can($request, $place),
            'limits' => ['one_quote' => Procurement::oneQuoteLimit(), 'quotes' => Procurement::quotesNeeded()],
            'to_order' => $toOrder->map(fn ($r) => [
                'id' => $r->id, 'number' => $r->number, 'purpose' => $r->purpose, 'amount' => (float) $r->amount, 'requested_by' => $r->requester?->full_name,
                'approved_at' => $r->decided_at?->toIso8601String(), 'quotes' => $r->quotations->count(), 'chosen' => $r->quotations->contains('chosen', true),
                'must_order' => Procurement::mustOrder($r),
            ])->values(),
            'orders' => $orders->map(fn ($po) => $this->presentOrder($po, $user))->values(),
            'bills' => $bills->map(fn ($b) => $this->presentBill($b, $user, $place))->values(),
            'suppliers' => $this->suppliers($place),
        ]);
    }

    /** GET /accounting/procurement/options - what an order offers: suppliers, what it is spent on, funds. */
    public function options(Request $request): JsonResponse
    {
        $place = $this->placeFor($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }

        return $this->ok([
            'suppliers' => $this->suppliers($place, true),
            'accounts' => $this->chart->usable($place)->filter(fn ($a) => $a->type === 'expense')->sortBy('code')
                ->map(fn ($a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name])->values(),
            'funds' => \App\Models\AccountingFund::where('is_active', true)->orderBy('display_order')->get(['id', 'code', 'name', 'is_restricted']),
            'limits' => ['one_quote' => Procurement::oneQuoteLimit(), 'quotes' => Procurement::quotesNeeded()],
            'church' => $place->territory_type->value === 'church',
            'today' => now()->toDateString(),
        ]);
    }

    // ------------------------------------------------------------ suppliers

    public function suppliersIndex(Request $request): JsonResponse
    {
        $place = $this->placeFor($request);

        return $place instanceof JsonResponse ? $place : $this->ok($this->suppliers($place));
    }

    public function saveSupplier(Request $request, ?int $id = null): JsonResponse
    {
        $place = $this->placeFor($request, 'procure');
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $s = $id ? Supplier::where('territory_id', $place->id)->find($id) : null;
        if ($id && ! $s) {
            return $this->notFound('That supplier isn\'t here.');
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'kra_pin' => ['nullable', 'string', 'max:20'],
            'pay_details' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            ...\App\Support\PayTo::rules(),
        ], ['name.required' => 'What is the supplier called?']);
        $s = $this->procurement->saveSupplier($place, $request->user(), $data, $s);

        return $this->ok($this->presentSupplier($s), $id ? 'Saved.' : "{$s->name} added.", $id ? 200 : 201);
    }

    public function removeSupplier(Request $request, int $id): JsonResponse
    {
        $place = $this->placeFor($request, 'procure');
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $s = Supplier::where('territory_id', $place->id)->find($id);

        return $s ? $this->ok(null, $this->procurement->removeSupplier($s)) : $this->notFound('That supplier isn\'t here.');
    }

    // ------------------------------------------------------------ quotations (on a requisition)

    public function addQuote(Request $request, int $id): JsonResponse
    {
        [$r, , $deny] = $this->quotable($request, $id);
        if ($deny) {
            return $deny;
        }
        $data = $request->validate([
            'supplier_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:1'],
            'notes' => ['nullable', 'string', 'max:255'],
            'file' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ], ['supplier_id.required' => 'Pick the supplier.', 'amount.required' => 'Enter their price.', 'file.max' => 'The file must be 5 MB or smaller.']);
        $q = $this->procurement->addQuote($r, $request->user(), $data, $request->file('file'));

        return $this->ok(self::presentQuote($q), "{$q->supplier->name}'s quotation added.", 201);
    }

    public function removeQuote(Request $request, int $id, int $quote): JsonResponse
    {
        [$r, , $deny] = $this->quotable($request, $id);
        if ($deny) {
            return $deny;
        }
        $q = Quotation::where('requisition_id', $r->id)->find($quote);
        if (! $q) {
            return $this->notFound('That quotation isn\'t on this requisition.');
        }
        $this->procurement->removeQuote($r, $q);

        return $this->ok(null, 'Removed.');
    }

    public function chooseQuote(Request $request, int $id, int $quote): JsonResponse
    {
        [$r, $place, $deny] = $this->quotable($request, $id);
        if ($deny) {
            return $deny;
        }
        if (! AccountingAccess::can($request->user(), $place, 'procure')) {
            return $this->forbidden('Whoever buys picks the quotation.');
        }
        $q = Quotation::where('requisition_id', $r->id)->find($quote);
        if (! $q) {
            return $this->notFound('That quotation isn\'t on this requisition.');
        }
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        $q = $this->procurement->chooseQuote($r, $q, $data['reason'] ?? null);

        return $this->ok(self::presentQuote($q), "Buying from {$q->supplier->name}.");
    }

    public function quoteFile(Request $request, int $id, int $quote): Response|JsonResponse
    {
        $r = Requisition::find($id);
        $place = $r ? Territory::find($r->territory_id) : null;
        $user = $request->user();
        $ok = $r && $place && ((int) $r->requested_by === (int) $user->id || AccountingAccess::canSeeProcurement($user, $place)
            || ($this->engine->latest($r) && $this->inbox->canView($user, $this->engine->latest($r))));
        $file = $ok ? Quotation::where('requisition_id', $r->id)->find($quote)?->getFirstMedia('quote') : null;

        return $file ? response()->file($file->getPath(), ['Content-Type' => $file->mime_type]) : $this->notFound('That quotation has no file.');
    }

    // ------------------------------------------------------------ the order

    /** POST /accounting/requisitions/{id}/order */
    public function raise(Request $request, int $id): JsonResponse
    {
        $r = Requisition::find($id);
        $place = $r ? Territory::find($r->territory_id) : null;
        if (! $r || ! $place || ! AccountingAccess::can($request->user(), $place, 'procure')) {
            return $this->forbidden('Whoever buys raises the order.');
        }
        $data = $request->validate([
            'supplier_id' => ['nullable', 'integer'],
            'date' => ['nullable', 'date'],
            'deliver_by' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:30'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0.01'],
            'lines.*.account_id' => ['nullable', 'integer'],
            'lines.*.fund_id' => ['nullable', 'integer'],
            'lines.*.is_asset' => ['nullable', 'boolean'],
        ], ['lines.required' => 'Add what is being ordered.', 'lines.*.description.required' => 'Say what the item is.']);
        $po = $this->procurement->raiseOrder($r, $request->user(), $data);

        return $this->ok($this->presentOrder($po, $request->user(), true), "Order {$po->number} raised to {$po->supplier->name}.", 201);
    }

    public function showOrder(Request $request, int $id): JsonResponse
    {
        [$po, , $deny] = $this->order($request, $id);

        return $deny ?? $this->ok($this->presentOrder($po, $request->user(), true));
    }

    /** POST /accounting/procurement/orders/{id}/receive {date, notes, lines: [{line_id, quantity}]} + files[] */
    public function receive(Request $request, int $id): JsonResponse
    {
        [$po, , $deny] = $this->order($request, $id, 'procure');
        if ($deny) {
            return $deny;
        }
        $data = $request->validate([
            'date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'max:30'],
            'lines.*.line_id' => ['required', 'integer'],
            'lines.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'files' => ['nullable', 'array', 'max:3'],
            'files.*' => ['file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ], ['files.*.max' => 'Each file must be 5 MB or smaller.']);
        $grn = $this->procurement->receive($po, $request->user(), $data, $request->file('files') ?? []);
        $made = $grn->lines->whereNotNull('equipment_id')->count();

        return $this->ok($this->presentOrder($po->fresh(), $request->user(), true), "Received on {$grn->number}.".($made ? " {$made} ".($made === 1 ? 'item was' : 'items were').' added to the church\'s equipment.' : ''), 201);
    }

    public function undoDelivery(Request $request, int $id): JsonResponse
    {
        $grn = GoodsReceived::find($id);
        [$po, , $deny] = $grn ? $this->order($request, $grn->purchase_order_id, 'procure') : [null, null, $this->notFound('That delivery isn\'t here.')];
        if ($deny) {
            return $deny;
        }
        $this->procurement->undoDelivery($grn, $request->user());

        return $this->ok($this->presentOrder($po->fresh(), $request->user(), true), "{$grn->number} undone.");
    }

    /** POST /accounting/procurement/orders/{id}/bill {supplier_ref, date, due_on, lines: [{line_id, quantity, unit_price}]} + file */
    public function bill(Request $request, int $id): JsonResponse
    {
        [$po, , $deny] = $this->order($request, $id, 'procure');
        if ($deny) {
            return $deny;
        }
        $data = $request->validate([
            'supplier_ref' => ['required', 'string', 'max:60'],
            'date' => ['required', 'date'],
            'due_on' => ['nullable', 'date'],
            'lines' => ['required', 'array', 'max:30'],
            'lines.*.line_id' => ['required', 'integer'],
            'lines.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'file' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ], ['supplier_ref.required' => 'Enter the number on the supplier\'s invoice.', 'file.max' => 'The file must be 5 MB or smaller.']);
        $bill = $this->procurement->bill($po, $request->user(), $data, $request->file('file'));

        return $this->ok($this->presentOrder($po->fresh(), $request->user(), true), "Bill {$bill->number} posted - KES ".number_format((float) $bill->amount, 2).' owed to '.$po->supplier->name.'.', 201);
    }

    public function close(Request $request, int $id): JsonResponse
    {
        [$po, , $deny] = $this->order($request, $id, 'procure');
        if ($deny) {
            return $deny;
        }
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        return $this->ok($this->presentOrder($this->procurement->close($po, $request->user(), $data['reason'] ?? null), $request->user(), true), 'Closed - nothing more is expected on it.');
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        [$po, , $deny] = $this->order($request, $id, 'procure');
        if ($deny) {
            return $deny;
        }
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], ['reason.required' => 'Say why it is cancelled.']);

        return $this->ok($this->presentOrder($this->procurement->cancel($po, $request->user(), $data['reason']), $request->user(), true), 'Cancelled - the requisition can be ordered again.');
    }

    // ------------------------------------------------------------ bills

    /** POST /accounting/procurement/bills/{id}/pay {pay_from_account_id} - the voucher, already authorised. */
    public function payBill(Request $request, int $id): JsonResponse
    {
        [$bill, $place, $deny] = $this->billFor($request, $id);
        if ($deny) {
            return $deny;
        }
        if (! AccountingAccess::can($request->user(), $place, 'prepare')) {
            return $this->forbidden('Your role can\'t make payments here.');
        }
        $data = $request->validate(['pay_from_account_id' => ['required', 'integer']]);
        $pv = $this->procurement->payBill($bill, $request->user(), (int) $data['pay_from_account_id']);

        return $this->ok(['voucher_id' => $pv->id, 'voucher_number' => $pv->number], "Voucher {$pv->number} is ready to pay - it is already authorised.", 201);
    }

    public function reverseBill(Request $request, int $id): JsonResponse
    {
        [$bill, $place, $deny] = $this->billFor($request, $id);
        if ($deny) {
            return $deny;
        }
        if (! AccountingAccess::can($request->user(), $place, 'procure')) {
            return $this->forbidden('Whoever buys reverses a bill.');
        }
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], ['reason.required' => 'Say why it is reversed.']);
        $bill = $this->procurement->reverseBill($bill, $request->user(), $data['reason']);

        return $this->ok($this->presentBill($bill, $request->user(), $place), "Bill {$bill->number} reversed.");
    }

    public function billFile(Request $request, int $id): Response|JsonResponse
    {
        [$bill, , $deny] = $this->billFor($request, $id);
        $file = $deny ? null : $bill->getFirstMedia('invoice');

        return $file ? response()->file($file->getPath(), ['Content-Type' => $file->mime_type]) : ($deny ?? $this->notFound('No invoice file on this bill.'));
    }

    public function deliveryFile(Request $request, int $id, int $media): Response|JsonResponse
    {
        $grn = GoodsReceived::find($id);
        [, , $deny] = $grn ? $this->order($request, $grn->purchase_order_id) : [null, null, $this->notFound('That delivery isn\'t here.')];
        $file = $deny ? null : $grn->getMedia('delivery')->firstWhere('id', $media);

        return $file ? response()->file($file->getPath(), ['Content-Type' => $file->mime_type]) : ($deny ?? $this->notFound('That file isn\'t on this delivery.'));
    }

    // ------------------------------------------------------------ presenting

    /** The quotations block of a requisition, for its window (RequisitionController). */
    public static function quotesBlock(Requisition $r, $user): ?array
    {
        if ($r->kind !== 'purchase') {
            return null;
        }
        $place = Territory::find($r->territory_id);
        $procure = $place && AccountingAccess::can($user, $place, 'procure');
        $open = in_array($r->status, ['submitted', 'returned', 'approved'], true);
        $quotes = $r->quotations()->with('supplier')->get();
        $po = $r->order()->with('supplier')->first();

        return [
            'must_order' => Procurement::mustOrder($r),
            'limit' => Procurement::oneQuoteLimit(),
            'needed' => Procurement::quotesNeeded(),
            'quotes' => $quotes->map(fn ($q) => self::presentQuote($q))->values(),
            'order' => $po ? ['id' => $po->id, 'number' => $po->number, 'status' => $po->status, 'status_label' => PurchaseOrder::STATUSES[$po->status], 'supplier' => $po->supplier?->name] : null,
            'can' => [
                'quote' => $open && ((int) $r->requested_by === (int) $user->id || $procure),
                'choose' => $open && $procure,
                'order' => $r->status === 'approved' && ! $r->payment_voucher_id && $procure,
            ],
        ];
    }

    public static function presentQuote(Quotation $q): array
    {
        return [
            'id' => $q->id, 'supplier_id' => $q->supplier_id, 'supplier' => $q->supplier?->name, 'amount' => (float) $q->amount, 'notes' => $q->notes,
            'chosen' => $q->chosen, 'chosen_reason' => $q->chosen_reason, 'file' => (bool) $q->getFirstMedia('quote'),
        ];
    }

    private function presentOrder(PurchaseOrder $po, $user, bool $full = false): array
    {
        $po->loadMissing(['supplier', 'lines', 'requisition']);
        $place = Territory::find($po->territory_id);
        $procure = AccountingAccess::can($user, $place, 'procure');
        $lines = $po->lines;
        $toReceive = $lines->sum(fn ($l) => max(0, $l->toReceive()));
        $toBill = $lines->sum(fn ($l) => max(0, $l->toBill()));
        $out = [
            'id' => $po->id,
            'number' => $po->number,
            'date' => $po->date->toDateString(),
            'deliver_by' => $po->deliver_by?->toDateString(),
            'late' => $po->isOpen() && $po->deliver_by && $po->deliver_by->lt(today()),
            'supplier' => $po->supplier?->name,
            'supplier_id' => $po->supplier_id,
            'amount' => (float) $po->amount,
            'status' => $po->status,
            'status_label' => PurchaseOrder::STATUSES[$po->status],
            'requisition' => $po->requisition ? ['id' => $po->requisition->id, 'number' => $po->requisition->number, 'purpose' => $po->requisition->purpose, 'status' => $po->requisition->status] : null,
            'items' => $lines->count(),
            'to_receive' => $toReceive > 0 && $po->isOpen(),
            'to_bill' => $toBill > 0 && $po->status !== 'cancelled',
            'can' => [
                'receive' => $procure && $po->isOpen(),
                'bill' => $procure && $toBill > 0 && $po->status !== 'cancelled',
                'close' => $procure && $po->status === 'part_received',
                'cancel' => $procure && $po->status === 'issued' && $lines->every(fn ($l) => (float) $l->received_qty <= 0),
            ],
        ];
        if ($full) {
            $po->loadMissing(['deliveries.lines', 'deliveries.receiver', 'bills.voucher', 'issuer']);
            $s = $po->supplier;
            $out += [
                'notes' => $po->notes,
                'end_reason' => $po->end_reason,
                'issued_by' => $po->issuer?->full_name,
                'supplier_info' => $s ? $this->presentSupplier($s) : null,
                'place' => $this->placeInfo($place),
                'lines' => $lines->map(fn ($l) => [
                    'id' => $l->id, 'description' => $l->description, 'quantity' => (float) $l->quantity, 'unit_price' => (float) $l->unit_price, 'amount' => (float) $l->amount,
                    'is_asset' => $l->is_asset, 'account' => $l->account ? "{$l->account->code} {$l->account->name}" : null,
                    'received' => (float) $l->received_qty, 'billed' => (float) $l->billed_qty, 'to_receive' => max(0, $l->toReceive()), 'to_bill' => max(0, $l->toBill()),
                ])->values(),
                'deliveries' => $po->deliveries->map(fn ($g) => [
                    'id' => $g->id, 'number' => $g->number, 'date' => $g->date->toDateString(), 'status' => $g->status, 'by' => $g->receiver?->full_name, 'notes' => $g->notes,
                    'lines' => $g->lines->map(fn ($gl) => ['line_id' => $gl->purchase_order_line_id, 'description' => $lines->firstWhere('id', $gl->purchase_order_line_id)?->description, 'quantity' => (float) $gl->quantity, 'equipment' => (bool) $gl->equipment_id])->values(),
                    'files' => $g->getMedia('delivery')->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'mime' => $m->mime_type])->values(),
                    'can_undo' => $procure && $g->status === 'received' && $g->lines->every(fn ($gl) => ($l = $lines->firstWhere('id', $gl->purchase_order_line_id))
                        && round((float) $l->received_qty - (float) $gl->quantity, 2) >= round((float) $l->billed_qty, 2)),
                ])->values(),
                'bills' => $po->bills->map(fn ($b) => $this->presentBill($b, $user, $place))->values(),
            ];
        }

        return $out;
    }

    private function presentBill(SupplierInvoice $b, $user, Territory $place): array
    {
        $b->loadMissing(['supplier', 'voucher', 'order']);
        $openVoucher = $b->voucher && $b->voucher->status !== 'cancelled';

        return [
            'id' => $b->id, 'number' => $b->number, 'supplier' => $b->supplier?->name, 'supplier_ref' => $b->supplier_ref,
            'date' => $b->date->toDateString(), 'due_on' => $b->due_on?->toDateString(), 'overdue' => $b->status === 'posted' && $b->due_on && $b->due_on->lt(today()),
            'amount' => (float) $b->amount, 'status' => $b->status, 'status_label' => SupplierInvoice::STATUSES[$b->status],
            'order' => $b->order ? ['id' => $b->order->id, 'number' => $b->order->number] : null,
            'voucher' => $openVoucher ? ['id' => $b->voucher->id, 'number' => $b->voucher->number, 'status' => $b->voucher->status] : null,
            'journal_id' => $b->journal_id, 'file' => (bool) $b->getFirstMedia('invoice'),
            'can' => [
                'pay' => $b->status === 'posted' && ! $openVoucher && AccountingAccess::can($user, $place, 'prepare'),
                'reverse' => $b->status === 'posted' && ! $openVoucher && AccountingAccess::can($user, $place, 'procure'),
            ],
        ];
    }

    private function presentSupplier(Supplier $s): array
    {
        return ['id' => $s->id, 'name' => $s->name, 'phone' => $s->phone, 'email' => $s->email, 'kra_pin' => $s->kra_pin, 'pay_details' => $s->pay_details,
            'payee' => $s->payee, 'payee_text' => \App\Support\PayTo::describe($s->payee) ?? $s->pay_details, 'notes' => $s->notes, 'is_active' => $s->is_active];
    }

    private function suppliers(Territory $place, bool $activeOnly = false): array
    {
        $owed = SupplierInvoice::where('territory_id', $place->id)->where('status', 'posted')->selectRaw('supplier_id, SUM(amount) as owed')->groupBy('supplier_id')->pluck('owed', 'supplier_id');
        $orders = PurchaseOrder::where('territory_id', $place->id)->where('status', '!=', 'cancelled')->selectRaw('supplier_id, COUNT(*) as n, SUM(amount) as total')->groupBy('supplier_id')->get()->keyBy('supplier_id');

        return Supplier::where('territory_id', $place->id)->when($activeOnly, fn ($q) => $q->where('is_active', true))->orderBy('name')->get()
            ->map(fn ($s) => $this->presentSupplier($s) + ['owed' => round((float) ($owed[$s->id] ?? 0), 2), 'orders' => (int) ($orders[$s->id]->n ?? 0), 'ordered' => round((float) ($orders[$s->id]->total ?? 0), 2)])
            ->values()->all();
    }

    // ------------------------------------------------------------ access

    private function placeFor(Request $request, ?string $ability = null): Territory|JsonResponse
    {
        $id = $request->input('territory_id', $request->query('territory_id'));
        $place = PlaceAccess::place($request->user(), $id !== null && ctype_digit((string) $id) ? (int) $id : null);
        if (! $place || ! AccountingAccess::canSeeProcurement($request->user(), $place)) {
            return $this->forbidden('Procurement here isn\'t yours to see.');
        }
        if ($ability && ! AccountingAccess::can($request->user(), $place, $ability)) {
            return $this->forbidden('Your role can\'t buy for this place.');
        }

        return $place;
    }

    /** @return array{0: ?PurchaseOrder, 1: ?Territory, 2: ?JsonResponse} */
    private function order(Request $request, int $id, ?string $ability = null): array
    {
        $po = PurchaseOrder::find($id);
        $place = $po ? Territory::find($po->territory_id) : null;
        if (! $po || ! $place || ! AccountingAccess::canSeeProcurement($request->user(), $place)) {
            return [null, null, $this->notFound('That order isn\'t yours to see.')];
        }
        if ($ability && ! AccountingAccess::can($request->user(), $place, $ability)) {
            return [null, null, $this->forbidden('Your role can\'t buy for this place.')];
        }

        return [$po, $place, null];
    }

    /** @return array{0: ?SupplierInvoice, 1: ?Territory, 2: ?JsonResponse} */
    private function billFor(Request $request, int $id): array
    {
        $b = SupplierInvoice::find($id);
        $place = $b ? Territory::find($b->territory_id) : null;
        if (! $b || ! $place || ! AccountingAccess::canSeeProcurement($request->user(), $place)) {
            return [null, null, $this->notFound('That bill isn\'t yours to see.')];
        }

        return [$b, $place, null];
    }

    /** The person who asked, or whoever buys, adds quotations. @return array{0: ?Requisition, 1: ?Territory, 2: ?JsonResponse} */
    private function quotable(Request $request, int $id): array
    {
        $r = Requisition::find($id);
        $place = $r ? Territory::find($r->territory_id) : null;
        if (! $r || ! $place) {
            return [null, null, $this->notFound('That requisition isn\'t here.')];
        }
        $user = $request->user();
        if ((int) $r->requested_by !== (int) $user->id && ! AccountingAccess::can($user, $place, 'procure')) {
            return [null, null, $this->forbidden('The person who asked, or whoever buys, adds quotations.')];
        }

        return [$r, $place, null];
    }

    private function can(Request $request, Territory $place): array
    {
        $a = AccountingAccess::abilities($request->user(), $place);

        return ['procure' => $a['procure'], 'pay' => $a['prepare'], 'books' => $a['read'], 'own' => $a['own']];
    }
}
