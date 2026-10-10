<?php

namespace App\Services\Accounting;

use App\Models\Equipment;
use App\Models\GoodsReceived;
use App\Models\PaymentVoucher;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Quotation;
use App\Models\Requisition;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\Territory;
use App\Models\User;
use App\Services\Settings\Settings;
use App\Support\PayTo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Procurement (docs/specs/accounting-spec.md, A5): buying the standard way,
 * but only when it is big enough to need it. An approved purchase
 * requisition gets its quotations, then a local purchase order to the chosen
 * supplier; goods are received against the order, and the supplier's bill is
 * matched to what was ordered and received before it is posted (Dr expense or
 * fixed assets / Cr suppliers payable). Paying the bill is a voucher that is
 * already authorised - the requisition's approval carries through.
 */
final class Procurement
{
    public function __construct(private Documents $docs, private Ledger $ledger, private Numbering $numbering, private Chart $chart, private BudgetBridge $bridge, private PaymentVouchers $vouchers) {}

    public static function oneQuoteLimit(): float
    {
        return max(0, (float) (app(Settings::class)->system('procurement.one_quote_limit') ?? 50000));
    }

    public static function quotesNeeded(): int
    {
        return max(1, (int) (app(Settings::class)->system('procurement.quotes_needed') ?? 3));
    }

    /** Must this requisition be bought through an order (a purchase above the limit)? */
    public static function mustOrder(Requisition $r): bool
    {
        return $r->kind === 'purchase' && (float) $r->amount > self::oneQuoteLimit();
    }

    // ------------------------------------------------------------ suppliers

    public function saveSupplier(Territory $place, User $user, array $data, ?Supplier $s = null): Supplier
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw ValidationException::withMessages(['name' => ['What is the supplier called?']]);
        }
        $clash = Supplier::where('territory_id', $place->id)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->when($s, fn ($q) => $q->where('id', '!=', $s->id))->exists();
        if ($clash) {
            throw ValidationException::withMessages(['name' => ['There is already a supplier with that name.']]);
        }
        $fields = [
            'name' => mb_substr($name, 0, 150),
            'phone' => $this->clean($data['phone'] ?? null, 30),
            'email' => $this->clean($data['email'] ?? null, 150),
            'kra_pin' => ($pin = $this->clean($data['kra_pin'] ?? null, 20)) ? strtoupper($pin) : null,
            'pay_details' => $this->clean($data['pay_details'] ?? null, 255),
            // Where to pay them, in a shape a treasurer can pay from (M-Pesa, Airtel, paybill, till, bank, cash).
            'payee' => array_key_exists('payee', $data) ? PayTo::from($data['payee']) : $s?->payee,
            'notes' => $this->clean($data['notes'] ?? null, 255),
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : ($s?->is_active ?? true),
        ];
        if ($s) {
            $s->update($fields);

            return $s->fresh();
        }

        return Supplier::create($fields + ['territory_id' => $place->id, 'created_by' => $user->id]);
    }

    /** Remove a supplier never used; one that was used is switched off instead. */
    public function removeSupplier(Supplier $s): string
    {
        if ($s->isUsed()) {
            $s->update(['is_active' => false]);

            return 'Switched off - they have quotes or orders, so they stay on record.';
        }
        $s->delete();

        return 'Removed.';
    }

    // ------------------------------------------------------------ quotations

    public function addQuote(Requisition $r, User $user, array $data, ?UploadedFile $file = null): Quotation
    {
        $this->assertQuotable($r);
        $supplier = $this->supplier(Territory::findOrFail($r->territory_id), (int) ($data['supplier_id'] ?? 0));
        if (Quotation::where('requisition_id', $r->id)->where('supplier_id', $supplier->id)->exists()) {
            throw ValidationException::withMessages(['supplier_id' => ["{$supplier->name} has already quoted - remove theirs to change it."]]);
        }
        $q = Quotation::create([
            'requisition_id' => $r->id,
            'supplier_id' => $supplier->id,
            'amount' => $this->docs->amount($data['amount'] ?? 0, 'amount'),
            'notes' => $this->clean($data['notes'] ?? null, 255),
            'added_by' => $user->id,
        ]);
        if ($file) {
            $q->addMedia($file)->usingFileName(Str::uuid().'.'.strtolower($file->getClientOriginalExtension() ?: 'bin'))
                ->usingName(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) ?: 'Quote')->toMediaCollection('quote');
        }

        return $q->fresh('supplier');
    }

    public function removeQuote(Requisition $r, Quotation $q): void
    {
        $this->assertQuotable($r);
        $q->delete();
    }

    /** Pick the quotation to buy from - with a reason when it isn't the cheapest. */
    public function chooseQuote(Requisition $r, Quotation $q, ?string $reason): Quotation
    {
        $this->assertQuotable($r);
        $cheapest = (float) Quotation::where('requisition_id', $r->id)->min('amount');
        $reason = trim((string) $reason);
        if ((float) $q->amount > $cheapest && $reason === '') {
            throw ValidationException::withMessages(['reason' => ['It isn\'t the cheapest - say why this one.']]);
        }

        return DB::transaction(function () use ($r, $q, $reason) {
            Quotation::where('requisition_id', $r->id)->update(['chosen' => false, 'chosen_reason' => null]);
            $q->update(['chosen' => true, 'chosen_reason' => $reason !== '' ? mb_substr($reason, 0, 255) : null]);

            return $q->fresh('supplier');
        });
    }

    private function assertQuotable(Requisition $r): void
    {
        if ($r->kind !== 'purchase') {
            throw ValidationException::withMessages(['requisition' => ['Quotations are for buying something.']]);
        }
        if (! in_array($r->status, ['submitted', 'returned', 'approved'], true)) {
            throw ValidationException::withMessages(['requisition' => ['The quotations are settled once it is ordered.']]);
        }
    }

    // ------------------------------------------------------------ the order

    /**
     * Raise the local purchase order from an approved purchase requisition.
     * Above the limit it goes to the chosen quotation's supplier, once enough
     * quotations are in; its lines can't add up to more than was approved.
     */
    public function raiseOrder(Requisition $r, User $user, array $data): PurchaseOrder
    {
        if ($r->kind !== 'purchase' || $r->status !== 'approved' || $r->payment_voucher_id) {
            throw ValidationException::withMessages(['requisition' => ['Only an approved purchase, not yet paid, can be ordered.']]);
        }
        $place = Territory::findOrFail($r->territory_id);
        if (self::mustOrder($r)) {
            $count = Quotation::where('requisition_id', $r->id)->count();
            if ($count < self::quotesNeeded()) {
                throw ValidationException::withMessages(['quotes' => ['Above KES '.number_format(self::oneQuoteLimit()).' it needs '.self::quotesNeeded().' quotations - there '.($count === 1 ? 'is 1' : "are {$count}").'.']]);
            }
            $chosen = Quotation::where('requisition_id', $r->id)->where('chosen', true)->first();
            if (! $chosen) {
                throw ValidationException::withMessages(['quotes' => ['Pick the quotation to buy from first.']]);
            }
            $supplier = $this->supplier($place, $chosen->supplier_id, true);
        } else {
            $chosen = Quotation::where('requisition_id', $r->id)->where('chosen', true)->first();
            $supplier = $this->supplier($place, (int) ($data['supplier_id'] ?? $chosen?->supplier_id ?? 0));
        }
        $lines = $this->orderLines($place, $r, $data['lines'] ?? []);
        $total = round(array_sum(array_column($lines, 'amount')), 2);
        if ($total > round((float) $r->amount, 2)) {
            throw ValidationException::withMessages(['lines' => ['The order comes to KES '.number_format($total, 2).' - more than the KES '.number_format((float) $r->amount, 2).' approved. Ask again for the difference.']]);
        }
        $date = $data['date'] ?? now()->toDateString();
        $this->docs->notFuture($date);
        if (! empty($data['deliver_by']) && strtotime($data['deliver_by']) < strtotime($date)) {
            throw ValidationException::withMessages(['deliver_by' => ['Delivery can\'t be before the order.']]);
        }

        return DB::transaction(function () use ($r, $user, $place, $supplier, $lines, $total, $date, $data) {
            $r = Requisition::whereKey($r->id)->lockForUpdate()->firstOrFail();
            if ($r->status !== 'approved') {
                throw ValidationException::withMessages(['requisition' => ['It was ordered or changed a moment ago.']]);
            }
            $po = PurchaseOrder::create([
                'territory_id' => $place->id,
                'number' => $this->numbering->next($place, 'order', (int) date('Y', strtotime($date))),
                'requisition_id' => $r->id,
                'supplier_id' => $supplier->id,
                'date' => $date,
                'deliver_by' => $data['deliver_by'] ?? null,
                'notes' => $this->clean($data['notes'] ?? null, 500),
                'amount' => $total,
                'status' => 'issued',
                'issued_by' => $user->id,
            ]);
            $po->lines()->createMany($lines);
            $r->update(['status' => 'ordered']);

            return $po->fresh(['lines', 'supplier']);
        });
    }

    /** @return list<array<string, mixed>> */
    private function orderLines(Territory $place, Requisition $r, array $input): array
    {
        if (! $input) {
            throw ValidationException::withMessages(['lines' => ['Add what is being ordered.']]);
        }
        $out = [];
        foreach ($input as $i => $l) {
            $desc = trim((string) ($l['description'] ?? ''));
            if ($desc === '') {
                throw ValidationException::withMessages(["lines.{$i}.description" => ['Say what the item is.']]);
            }
            $qty = round((float) ($l['quantity'] ?? 0), 2);
            if ($qty <= 0) {
                throw ValidationException::withMessages(["lines.{$i}.quantity" => ['Enter how many.']]);
            }
            $asset = (bool) ($l['is_asset'] ?? false);
            if ($asset && floor($qty) !== $qty) {
                throw ValidationException::withMessages(["lines.{$i}.quantity" => ['Count equipment in whole items.']]);
            }
            $price = $this->docs->amount($l['unit_price'] ?? 0, "lines.{$i}.unit_price");
            if ($asset) {
                $account = $this->chart->account('fixed_assets');
                // Equipment still counts against the budget line the purchase was approved on.
                $budgetLine = $r->budget_line_id;
            } else {
                $account = $this->docs->postable($place, (int) ($l['account_id'] ?? $r->account_id), "lines.{$i}.account_id");
                if ($account->type !== 'expense') {
                    throw ValidationException::withMessages(["lines.{$i}.account_id" => ['Pick what it is spent on, or tick it as equipment.']]);
                }
                $budgetLine = $this->docs->budgetLine($place, $account, (int) $account->id === (int) $r->account_id ? $r->budget_line_id : null, "lines.{$i}.account_id");
            }
            $out[] = [
                'description' => mb_substr($desc, 0, 255),
                'quantity' => $qty,
                'unit_price' => $price,
                'amount' => round($qty * $price, 2),
                'account_id' => $account->id,
                'fund_id' => $this->docs->fund($l['fund_id'] ?? $r->fund_id, "lines.{$i}.fund_id"),
                'budget_line_id' => $budgetLine,
                'is_asset' => $asset,
            ];
        }

        return $out;
    }

    /** Cancel an order nothing has come on: the requisition is approved again. */
    public function cancel(PurchaseOrder $po, User $user, string $reason): PurchaseOrder
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => ['Say why it is cancelled.']]);
        }

        return DB::transaction(function () use ($po, $user, $reason) {
            $po = PurchaseOrder::whereKey($po->id)->lockForUpdate()->firstOrFail();
            if ($po->status !== 'issued' || $po->lines()->where('received_qty', '>', 0)->exists()) {
                throw ValidationException::withMessages(['order' => ['Goods have come on this order - close it instead.']]);
            }
            $po->update(['status' => 'cancelled', 'ended_by' => $user->id, 'ended_at' => now(), 'end_reason' => mb_substr($reason, 0, 255)]);
            Requisition::whereKey($po->requisition_id)->update(['status' => 'approved']);

            return $po->fresh(['lines', 'supplier']);
        });
    }

    /** Close a part-delivered order: nothing more is expected on it. */
    public function close(PurchaseOrder $po, User $user, ?string $reason): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $user, $reason) {
            $po = PurchaseOrder::whereKey($po->id)->lockForUpdate()->firstOrFail();
            if ($po->status !== 'part_received') {
                throw ValidationException::withMessages(['order' => [$po->status === 'issued' ? 'Nothing has come on it - cancel it instead.' : 'Only an order part-delivered can be closed.']]);
            }
            $po->update(['status' => 'closed', 'ended_by' => $user->id, 'ended_at' => now(), 'end_reason' => $this->clean($reason, 255)]);
            $this->settle($po);

            return $po->fresh(['lines', 'supplier']);
        });
    }

    // ------------------------------------------------------------ goods received

    /** Record a delivery: how many of each line came. Equipment bought by a church goes into Facilities. */
    public function receive(PurchaseOrder $po, User $user, array $data, array $files = []): GoodsReceived
    {
        $date = (string) ($data['date'] ?? now()->toDateString());
        $this->docs->notFuture($date);
        if (strtotime($date) < strtotime($po->date->toDateString())) {
            throw ValidationException::withMessages(['date' => ['Goods can\'t come before the order.']]);
        }

        return DB::transaction(function () use ($po, $user, $data, $date, $files) {
            $po = PurchaseOrder::whereKey($po->id)->lockForUpdate()->firstOrFail();
            if (! $po->isOpen()) {
                throw ValidationException::withMessages(['order' => ['Nothing more is expected on this order.']]);
            }
            $place = Territory::findOrFail($po->territory_id);
            $lines = $po->lines()->get()->keyBy('id');
            $take = [];
            foreach ($data['lines'] ?? [] as $i => $l) {
                $qty = round((float) ($l['quantity'] ?? 0), 2);
                if ($qty <= 0) {
                    continue;
                }
                $line = $lines[(int) ($l['line_id'] ?? 0)] ?? null;
                if (! $line) {
                    throw ValidationException::withMessages(["lines.{$i}.line_id" => ['That isn\'t on this order.']]);
                }
                if ($qty > $line->toReceive()) {
                    throw ValidationException::withMessages(["lines.{$i}.quantity" => ["Only {$this->qty($line->toReceive())} of \"{$line->description}\" are still to come."]]);
                }
                if ($line->is_asset && floor($qty) !== $qty) {
                    throw ValidationException::withMessages(["lines.{$i}.quantity" => ['Count equipment in whole items.']]);
                }
                $take[] = [$line, $qty];
            }
            if (! $take) {
                throw ValidationException::withMessages(['lines' => ['Enter how many came of at least one item.']]);
            }
            $grn = GoodsReceived::create([
                'territory_id' => $place->id,
                'number' => $this->numbering->next($place, 'delivery', (int) date('Y', strtotime($date))),
                'purchase_order_id' => $po->id,
                'date' => $date,
                'notes' => $this->clean($data['notes'] ?? null, 255),
                'status' => 'received',
                'received_by' => $user->id,
            ]);
            $supplier = $po->supplier;
            foreach ($take as [$line, $qty]) {
                $equipment = null;
                if ($line->is_asset && $place->territory_type->value === 'church') {
                    $equipment = Equipment::create([
                        'territory_id' => $place->id,
                        'name' => mb_substr($line->description, 0, 120),
                        'category' => 'other',
                        'quantity' => (int) $qty,
                        'condition' => 'good',
                        'bought_on' => $date,
                        'value' => round($qty * (float) $line->unit_price, 2),
                        'supplier' => $supplier ? mb_substr($supplier->name, 0, 120) : null,
                        'notes' => "Bought on order {$po->number}, received on {$grn->number}.",
                    ]);
                }
                $grn->lines()->create(['purchase_order_line_id' => $line->id, 'quantity' => $qty, 'equipment_id' => $equipment?->id]);
                $line->update(['received_qty' => round((float) $line->received_qty + $qty, 2)]);
            }
            foreach ($files as $f) {
                $grn->addMedia($f)->usingFileName(Str::uuid().'.'.strtolower($f->getClientOriginalExtension() ?: 'bin'))
                    ->usingName(pathinfo($f->getClientOriginalName(), PATHINFO_FILENAME) ?: 'Delivery note')->toMediaCollection('delivery');
            }
            $this->restatus($po);

            return $grn->fresh('lines');
        });
    }

    /** Undo a delivery recorded by mistake - while nothing on it is billed. Its equipment goes too. */
    public function undoDelivery(GoodsReceived $grn, User $user): GoodsReceived
    {
        return DB::transaction(function () use ($grn, $user) {
            $po = PurchaseOrder::whereKey($grn->purchase_order_id)->lockForUpdate()->firstOrFail();
            $grn = GoodsReceived::whereKey($grn->id)->lockForUpdate()->firstOrFail();
            if ($grn->status !== 'received') {
                throw ValidationException::withMessages(['delivery' => ['It was already undone.']]);
            }
            foreach ($grn->lines()->get() as $gl) {
                $line = PurchaseOrderLine::findOrFail($gl->purchase_order_line_id);
                if (round((float) $line->received_qty - (float) $gl->quantity, 2) < round((float) $line->billed_qty, 2)) {
                    throw ValidationException::withMessages(['delivery' => ["\"{$line->description}\" from this delivery is billed - reverse the bill first."]]);
                }
                $line->update(['received_qty' => round((float) $line->received_qty - (float) $gl->quantity, 2)]);
                if ($gl->equipment_id) {
                    Equipment::whereKey($gl->equipment_id)->first()?->delete();
                }
            }
            $grn->update(['status' => 'undone', 'undone_by' => $user->id, 'undone_at' => now()]);
            $this->restatus($po, true);

            return $grn->fresh('lines');
        });
    }

    // ------------------------------------------------------------ the bill

    /**
     * Post the supplier's bill, matched three ways: per line no more than was
     * received and not yet billed, at no more than the order's price. Owes the
     * supplier - Dr each line's account / Cr suppliers payable.
     */
    public function bill(PurchaseOrder $po, User $user, array $data, ?UploadedFile $file = null): SupplierInvoice
    {
        $ref = trim((string) ($data['supplier_ref'] ?? ''));
        if ($ref === '') {
            throw ValidationException::withMessages(['supplier_ref' => ['Enter the number on the supplier\'s invoice.']]);
        }
        $date = (string) ($data['date'] ?? now()->toDateString());
        $this->docs->notFuture($date);
        if (! empty($data['due_on']) && strtotime($data['due_on']) < strtotime($date)) {
            throw ValidationException::withMessages(['due_on' => ['It can\'t fall due before its date.']]);
        }

        return DB::transaction(function () use ($po, $user, $data, $ref, $date, $file) {
            $po = PurchaseOrder::whereKey($po->id)->lockForUpdate()->firstOrFail();
            if ($po->status === 'cancelled') {
                throw ValidationException::withMessages(['order' => ['This order was cancelled.']]);
            }
            if (SupplierInvoice::where('supplier_id', $po->supplier_id)->where('supplier_ref', $ref)->where('status', '!=', 'reversed')->exists()) {
                throw ValidationException::withMessages(['supplier_ref' => ["Their invoice {$ref} is already in the books."]]);
            }
            $place = Territory::findOrFail($po->territory_id);
            $supplier = $po->supplier;
            $lines = $po->lines()->get()->keyBy('id');
            $take = [];
            foreach ($data['lines'] ?? [] as $i => $l) {
                $qty = round((float) ($l['quantity'] ?? 0), 2);
                if ($qty <= 0) {
                    continue;
                }
                $line = $lines[(int) ($l['line_id'] ?? 0)] ?? null;
                if (! $line) {
                    throw ValidationException::withMessages(["lines.{$i}.line_id" => ['That isn\'t on this order.']]);
                }
                if ($qty > $line->toBill()) {
                    throw ValidationException::withMessages(["lines.{$i}.quantity" => [$line->toBill() > 0
                        ? "Only {$this->qty($line->toBill())} of \"{$line->description}\" were received and not yet billed."
                        : "\"{$line->description}\" has nothing received that isn't billed - receive the goods first."]]);
                }
                $price = $this->docs->amount($l['unit_price'] ?? $line->unit_price, "lines.{$i}.unit_price");
                if ($price > round((float) $line->unit_price, 2)) {
                    throw ValidationException::withMessages(["lines.{$i}.unit_price" => ["\"{$line->description}\" was ordered at KES ".number_format((float) $line->unit_price, 2).' - the bill can\'t charge more.']]);
                }
                $take[] = [$line, $qty, $price, round($qty * $price, 2)];
            }
            if (! $take) {
                throw ValidationException::withMessages(['lines' => ['Enter what the bill charges for.']]);
            }
            $total = round(array_sum(array_column($take, 3)), 2);
            $journalLines = array_map(fn ($t) => [
                'account_id' => $t[0]->account_id, 'debit' => $t[3], 'fund_id' => $t[0]->fund_id, 'budget_line_id' => $t[0]->budget_line_id, 'memo' => $t[0]->description,
            ], $take);
            // Owed in the same fund it is spent from, so each fund's money stays its own.
            foreach ($this->byFund($take) as $fund => $amount) {
                $journalLines[] = ['account_id' => $this->chart->account('suppliers_payable')->id, 'credit' => $amount, 'fund_id' => $fund ?: null, 'memo' => $supplier?->name];
            }
            $journal = $this->ledger->post($place, [
                'doc_type' => 'bill',
                'date' => $date,
                'narration' => "{$supplier?->name} invoice {$ref} on order {$po->number}",
                'party_name' => $supplier?->name,
                'party_phone' => $supplier?->phone,
                'reference' => mb_substr($ref, 0, 60),
                'source_type' => 'supplier_invoice',
            ], $journalLines, $user);
            $bill = SupplierInvoice::create([
                'territory_id' => $place->id,
                'number' => $journal->number,
                'supplier_id' => $po->supplier_id,
                'purchase_order_id' => $po->id,
                'supplier_ref' => mb_substr($ref, 0, 60),
                'date' => $date,
                'due_on' => $data['due_on'] ?? null,
                'amount' => $total,
                'status' => 'posted',
                'journal_id' => $journal->id,
                'posted_by' => $user->id,
            ]);
            $journal->forceFill(['source_id' => $bill->id])->saveQuietly();
            foreach ($take as [$line, $qty, $price, $amount]) {
                $bill->lines()->create(['purchase_order_line_id' => $line->id, 'description' => $line->description, 'quantity' => $qty, 'unit_price' => $price, 'amount' => $amount]);
                $line->update(['billed_qty' => round((float) $line->billed_qty + $qty, 2)]);
            }
            if ($file) {
                $bill->addMedia($file)->usingFileName(Str::uuid().'.'.strtolower($file->getClientOriginalExtension() ?: 'bin'))
                    ->usingName(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) ?: 'Invoice')->toMediaCollection('invoice');
            }
            $this->bridge->journalPosted($journal, $user);

            return $bill->fresh('lines');
        });
    }

    /** Reverse a bill not yet paid: its journal is reversed and its quantities can be billed again. */
    public function reverseBill(SupplierInvoice $bill, User $user, string $reason): SupplierInvoice
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => ['Say why it is reversed.']]);
        }

        return DB::transaction(function () use ($bill, $user, $reason) {
            $bill = SupplierInvoice::whereKey($bill->id)->lockForUpdate()->firstOrFail();
            if ($bill->status !== 'posted') {
                throw ValidationException::withMessages(['bill' => [$bill->status === 'paid' ? 'It is paid - reverse the payment first.' : 'It was already reversed.']]);
            }
            if ($bill->payment_voucher_id && PaymentVoucher::whereKey($bill->payment_voucher_id)->where('status', '!=', 'cancelled')->exists()) {
                throw ValidationException::withMessages(['bill' => ['A payment voucher is open for it - cancel the voucher first.']]);
            }
            $journal = \App\Models\Journal::findOrFail($bill->journal_id);
            $this->ledger->reverse($journal, $user, $reason, max(now()->toDateString(), $journal->date->toDateString()));
            $this->bridge->journalReversed($journal, $user);
            foreach ($bill->lines()->get() as $bl) {
                $line = PurchaseOrderLine::find($bl->purchase_order_line_id);
                $line?->update(['billed_qty' => max(0, round((float) $line->billed_qty - (float) $bl->quantity, 2))]);
            }
            $bill->update(['status' => 'reversed', 'payment_voucher_id' => null]);

            return $bill->fresh('lines');
        });
    }

    /**
     * Pay the bill: a payment voucher already authorised (Dr suppliers payable
     * / Cr the account paid from) by whoever approved the requisition.
     */
    public function payBill(SupplierInvoice $bill, User $user, int $payFromAccountId): PaymentVoucher
    {
        return DB::transaction(function () use ($bill, $user, $payFromAccountId) {
            $bill = SupplierInvoice::whereKey($bill->id)->lockForUpdate()->firstOrFail();
            if ($bill->status !== 'posted') {
                throw ValidationException::withMessages(['bill' => ['Only a bill still to pay can be paid.']]);
            }
            if ($bill->payment_voucher_id && PaymentVoucher::whereKey($bill->payment_voucher_id)->where('status', '!=', 'cancelled')->exists()) {
                throw ValidationException::withMessages(['bill' => ['A payment voucher is already open for it.']]);
            }
            $place = Territory::findOrFail($bill->territory_id);
            $po = PurchaseOrder::findOrFail($bill->purchase_order_id);
            $r = Requisition::findOrFail($po->requisition_id);
            $supplier = $bill->supplier;
            $pv = $this->vouchers->prepareAuthorised($place, $user, [
                'date' => now()->toDateString(),
                'payee_name' => $supplier?->name ?? 'Supplier',
                'payee_phone' => $supplier?->phone,
                'payee' => $supplier?->payee,
                'pay_from_account_id' => $payFromAccountId,
                'narration' => "Bill {$bill->number} ({$supplier?->name} invoice {$bill->supplier_ref}) on order {$po->number}",
                'purpose' => 'bill',
                'supplier_invoice_id' => $bill->id,
                'lines' => collect($this->byFund($bill->lines()->with('orderLine')->get()->map(fn ($l) => [$l->orderLine, 0, 0, (float) $l->amount])->all()))
                    ->map(fn ($amount, $fund) => ['account_id' => $this->chart->account('suppliers_payable')->id, 'fund_id' => $fund ?: null, 'amount' => $amount, 'description' => "{$supplier?->name} invoice {$bill->supplier_ref}"])->values()->all(),
            ], $r->decided_by, "Order {$po->number} for requisition {$r->number}, approved");
            $bill->update(['payment_voucher_id' => $pv->id]);

            return $pv;
        });
    }

    /** Its voucher was paid: the bill is paid, and the requisition too once everything on the order is. */
    public function voucherPaid(PaymentVoucher $pv): void
    {
        $bill = SupplierInvoice::find($pv->supplier_invoice_id);
        if (! $bill || $bill->status !== 'posted') {
            return;
        }
        $bill->update(['status' => 'paid', 'payment_voucher_id' => $pv->id]);
        $this->settle(PurchaseOrder::findOrFail($bill->purchase_order_id));
    }

    /** Its payment was reversed: the bill is owed again. */
    public function voucherUnpaid(PaymentVoucher $pv): void
    {
        $bill = SupplierInvoice::find($pv->supplier_invoice_id);
        if (! $bill || $bill->status !== 'paid') {
            return;
        }
        $bill->update(['status' => 'posted']);
        $this->settle(PurchaseOrder::findOrFail($bill->purchase_order_id));
    }

    /** Its voucher was cancelled: the bill can be paid with a new one. */
    public function voucherCancelled(PaymentVoucher $pv): void
    {
        SupplierInvoice::whereKey($pv->supplier_invoice_id)->where('payment_voucher_id', $pv->id)->where('status', 'posted')->update(['payment_voucher_id' => null]);
    }

    // ------------------------------------------------------------ helpers

    /** Ordered, part received or received, from what has come. */
    private function restatus(PurchaseOrder $po, bool $reopen = false): void
    {
        if (! $reopen && ! $po->isOpen()) {
            return;
        }
        $lines = $po->lines()->get();
        $all = $lines->every(fn ($l) => (float) $l->received_qty >= (float) $l->quantity);
        $any = $lines->contains(fn ($l) => (float) $l->received_qty > 0);
        $po->update(['status' => $all ? 'received' : ($any ? 'part_received' : 'issued'), 'ended_by' => null, 'ended_at' => null, 'end_reason' => null]);
        $this->settle($po->fresh());
    }

    /** The requisition is paid once the order is complete (received or closed) and every bill on it is paid. */
    private function settle(PurchaseOrder $po): void
    {
        $r = Requisition::find($po->requisition_id);
        if (! $r || ! in_array($r->status, ['ordered', 'paid'], true)) {
            return;
        }
        $lines = $po->lines()->get();
        $done = in_array($po->status, ['received', 'closed'], true)
            && $lines->every(fn ($l) => round((float) $l->billed_qty, 2) >= round((float) $l->received_qty, 2))
            && ! SupplierInvoice::where('purchase_order_id', $po->id)->where('status', 'posted')->exists()
            && SupplierInvoice::where('purchase_order_id', $po->id)->where('status', 'paid')->exists();
        $r->update(['status' => $done ? 'paid' : 'ordered']);
    }

    /** @param list<array{0: PurchaseOrderLine, 1: mixed, 2: mixed, 3: float}> $take @return array<int, float> fund id (0 = general) => amount */
    private function byFund(array $take): array
    {
        $out = [];
        foreach ($take as $t) {
            $fund = (int) ($t[0]?->fund_id ?? 0);
            $out[$fund] = round(($out[$fund] ?? 0) + $t[3], 2);
        }

        return $out;
    }

    private function supplier(Territory $place, int $id, bool $evenIfOff = false): Supplier
    {
        $s = Supplier::where('territory_id', $place->id)->find($id);
        if (! $s) {
            throw ValidationException::withMessages(['supplier_id' => ['Pick one of your suppliers.']]);
        }
        if (! $s->is_active && ! $evenIfOff) {
            throw ValidationException::withMessages(['supplier_id' => ["{$s->name} is switched off."]]);
        }

        return $s;
    }

    private function clean(mixed $v, int $max): ?string
    {
        $v = trim((string) ($v ?? ''));

        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    private function qty(float $q): string
    {
        return rtrim(rtrim(number_format($q, 2, '.', ''), '0'), '.');
    }
}
