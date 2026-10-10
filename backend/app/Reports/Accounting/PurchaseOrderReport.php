<?php

namespace App\Reports\Accounting;

use App\Models\PurchaseOrder;
use App\Reports\ReportColumn;
use App\Reports\ReportContext;
use App\Reports\ReportData;
use App\Reports\ReportSection;

/** The local purchase order (LPO) the supplier is given: what, how many, at what price, by when - signed. */
final class PurchaseOrderReport extends AccountingReport
{
    public function key(): string
    {
        return 'accounting.lpo';
    }

    public function title(): string
    {
        return 'Local purchase order (LPO)';
    }

    public function description(): string
    {
        return 'The order the supplier is given: items, quantities, prices, the total and the delivery date, with signatures.';
    }

    public function icon(): string
    {
        return 'ri-shopping-cart-2-line';
    }

    public function subject(): string
    {
        return 'local purchase order';
    }

    public function lockedOnly(): bool
    {
        return true;
    }

    public function inputs(): array
    {
        return ['record'];
    }

    public function checkParams(ReportContext $c): ?string
    {
        return $this->order($c) ? null : 'That order isn\'t in these books.';
    }

    private function order(ReportContext $c): ?PurchaseOrder
    {
        return PurchaseOrder::with(['lines', 'supplier', 'issuer', 'requisition'])->where('territory_id', $c->territory->id)->find((int) $c->param('record_id'));
    }

    public function build(ReportContext $context): ReportData
    {
        $o = $this->order($context);
        $rows = $o->lines->map(fn ($l) => [$l->description, (float) $l->quantity, (float) $l->unit_price, (float) $l->amount])->all();
        $s = $o->supplier;

        return new ReportData(
            kicker: $context->kicker('local purchase order'),
            title: "LPO {$o->number}",
            periodLabel: $this->day($o->date->toDateString()),
            scopeLabel: $context->territory->name,
            tiles: [
                ['label' => 'Order total', 'value' => $this->money($o->amount), 'tone' => 'primary'],
                ['label' => 'Deliver by', 'value' => $o->deliver_by ? $this->day($o->deliver_by->toDateString()) : 'As agreed', 'tone' => 'warning'],
                ['label' => 'Status', 'value' => PurchaseOrder::STATUSES[$o->status], 'tone' => 'muted'],
            ],
            meta: array_filter(['Supplier' => $s?->name, 'Phone' => $s?->phone, 'KRA PIN' => $s?->kra_pin, 'Pay to' => $s?->pay_details, 'Requisition' => $o->requisition?->number, 'Notes' => $o->notes]),
            sections: [new ReportSection('Please supply', [ReportColumn::text('Item', true), ReportColumn::number('Qty'), ReportColumn::money('Unit price', null), ReportColumn::money('KES')], $rows, 'Deliver with a delivery note quoting this LPO number. The bill is paid against this order and what was received.')],
            signatures: [['label' => 'Ordered by', 'name' => $o->issuer?->full_name ?? '', 'date' => $this->day($o->date->toDateString())], ['label' => 'Authorised by'], ['label' => 'Supplier']],
        );
    }
}
