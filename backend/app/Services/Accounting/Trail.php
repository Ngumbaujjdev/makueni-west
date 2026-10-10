<?php

namespace App\Services\Accounting;

use App\Models\ApprovalEvent;
use App\Models\ApprovalRequest;
use App\Models\Collection;
use App\Models\Journal;
use App\Models\PaymentVoucher;
use App\Models\PayrollRun;
use App\Models\PurchaseOrder;
use App\Models\Remittance;
use App\Models\Requisition;
use App\Models\StaffAdvance;
use App\Models\SupplierInvoice;
use App\Models\Territory;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * A record's trail (docs/specs/accounting-spec.md, Redesign R1): everything
 * that happened to it, newest first, in plain sentences - from its own who
 * and when columns, the approval engine's events and decisions, and the files
 * added - and the documents it is chained to (requisition → order → delivery
 * → bill → voucher → payment in the books).
 *
 * Read-only. Names are the people who acted at the place, never members.
 */
class Trail
{
    public const TYPES = [
        'voucher' => PaymentVoucher::class,
        'requisition' => Requisition::class,
        'payroll' => PayrollRun::class,
        'order' => PurchaseOrder::class,
        'remittance' => Remittance::class,
        'collection' => Collection::class,
        // Payee details / detail pages (docs/specs/accounting-spec.md): these carry a summary too - they have no page of their own.
        'bill' => SupplierInvoice::class,
        'receipt' => Journal::class,
        'gift' => \App\Models\Gift::class,
        'supplier' => \App\Models\Supplier::class,
        'employee' => \App\Models\Employee::class,
    ];

    /** The types whose trail also carries the record's summary. */
    public const SUMMARISED = ['bill', 'receipt', 'gift', 'supplier', 'employee'];

    /** The record, or null. */
    public function find(string $type, int $id): ?Model
    {
        $class = self::TYPES[$type] ?? null;
        $record = $class ? $class::find($id) : null;

        // "receipt" is any posted document in the books (a receipt, payment, transfer, journal...).
        return $record;
    }

    /** The places whose books show the record (a remittance shows on both sides). */
    public function places(Model $record): array
    {
        if ($record instanceof Remittance) {
            return array_values(array_filter([Territory::find($record->from_territory_id), Territory::find($record->to_territory_id)]));
        }

        return array_values(array_filter([Territory::find($record->territory_id)]));
    }

    /** @return array{events: array, links: array} */
    public function for(Model $record): array
    {
        $events = match (true) {
            $record instanceof PaymentVoucher => $this->voucherEvents($record),
            $record instanceof Requisition => $this->requisitionEvents($record),
            $record instanceof PayrollRun => $this->payrollEvents($record),
            $record instanceof PurchaseOrder => $this->orderEvents($record),
            $record instanceof Remittance => $this->remittanceEvents($record),
            $record instanceof Collection => $this->collectionEvents($record),
            $record instanceof SupplierInvoice => $this->billEvents($record),
            $record instanceof Journal => $this->journalEvents($record),
            $record instanceof \App\Models\Gift => $this->giftEvents($record),
            $record instanceof \App\Models\Supplier => $this->supplierEvents($record),
            $record instanceof \App\Models\Employee => $this->employeeEvents($record),
        };
        $events = array_merge($events, $this->approvalEvents($record), $this->fileEvents($record));
        $events = array_values(array_filter($events, fn ($e) => $e && $e['at']));
        // A plain date (sent on, banked on) counts as the end of that day, after the timed steps before it.
        $key = fn ($e) => strlen($e['at']) === 10 ? "{$e['at']}T23:59:59" : substr($e['at'], 0, 19);
        usort($events, fn ($a, $b) => strcmp($key($b), $key($a)) ?: ($b['order'] ?? 0) <=> ($a['order'] ?? 0));

        return [
            'events' => array_map(fn ($e) => array_diff_key($e, ['order' => 1]), $events),
            'links' => array_values(array_filter($this->links($record))),
        ] + (($summary = $this->summary($record)) ? ['summary' => $summary] : []);
    }

    // ------------------------------------------------------------ bill, receipt, gift, supplier, staff

    /** {title, number, amount, status, status_label, date, facts: [[label, value]], lines: [[label, amount]]} for the record pages without one of their own. */
    private function summary(Model $record): ?array
    {
        $pay = fn (?array $p, ?string $fallback = null) => \App\Support\PayTo::describe($p) ?? $fallback;
        $f = fn (array $facts) => array_values(array_filter($facts, fn ($x) => $x && $x[1] !== null && $x[1] !== ''));

        return match (true) {
            $record instanceof SupplierInvoice => [
                'title' => "Bill from {$record->supplier?->name}", 'number' => $record->number, 'amount' => (float) $record->amount, 'status' => $record->status,
                'status_label' => (defined(SupplierInvoice::class.'::STATUSES') ? SupplierInvoice::STATUSES[$record->status] ?? null : null) ?? ucfirst((string) $record->status),
                'date' => $record->date?->toDateString(),
                'facts' => $f([['Supplier', $record->supplier?->name], ['Their invoice', $record->supplier_ref], ['Order', $record->order?->number], ['Due on', $record->due_on?->format('j M Y')],
                    ['Pay to', $pay($record->supplier?->payee, $record->supplier?->pay_details)], ['Entered by', $this->name($record->posted_by)]]),
                'lines' => $record->lines()->get()->map(fn ($l) => [$l->description ?? 'Item', (float) $l->amount])->all(),
            ],
            $record instanceof Journal => [
                'title' => ucfirst(str_replace('_', ' ', (string) $record->doc_type)).($record->party_name ? " - {$record->party_name}" : ''), 'number' => $record->number,
                'amount' => (float) $record->amount, 'status' => $record->status, 'status_label' => $record->status === 'reversed' ? 'Reversed' : 'In the books', 'date' => $record->date?->toDateString(),
                'facts' => $f([['From / to', $record->party_name], ['Phone', $record->party_phone], ['Method', $record->method ? ucfirst($record->method) : null], ['Reference', $record->reference],
                    ['What for', $record->narration], ['Posted by', $this->name($record->posted_by)]]),
                'lines' => $record->lines()->with('account')->get()->map(fn ($l) => [trim(($l->account?->code ?? '').' '.($l->account?->name ?? '')).($l->memo ? " - {$l->memo}" : ''), (float) ($l->debit ?: -$l->credit)])->all(),
            ],
            $record instanceof \App\Models\Gift => [
                'title' => 'Gift'.($record->giver_name ? " from {$record->giver_name}" : ''), 'number' => $record->reference, 'amount' => (float) $record->amount, 'status' => $record->status,
                'status_label' => \App\Models\Gift::STATUSES[$record->status] ?? $record->status, 'date' => ($record->paid_at ?? $record->created_at)?->toDateString(),
                'facts' => $f([['For', Paybill::PURPOSES[$record->purpose][0] ?? $record->purpose], ['Giver', $record->giver_name], ['Phone', $record->giver_phone], ['Email', $record->giver_email],
                    ['Paid by', $record->method === 'mpesa' ? 'M-Pesa' : 'Card or M-Pesa on Paystack'], ['Reference', $record->provider_ref],
                    ['Fee', (float) $record->fee ? $this->money($record->fee) : null], ['Diocese share', (float) $record->split ? $this->money($record->split) : null], ['Why', in_array($record->status, ['failed', 'abandoned', 'refunded'], true) ? $record->result : null]]),
                'lines' => [],
            ],
            $record instanceof \App\Models\Supplier => [
                'title' => $record->name, 'number' => null, 'amount' => (float) SupplierInvoice::where('supplier_id', $record->id)->where('status', 'posted')->sum('amount'),
                'status' => $record->is_active ? 'active' : 'off', 'status_label' => $record->is_active ? 'Active' : 'Not used now', 'date' => $record->created_at?->toDateString(),
                'facts' => $f([['Pay to', $pay($record->payee, $record->pay_details)], ['Phone', $record->phone], ['Email', $record->email], ['KRA PIN', $record->kra_pin], ['Notes', $record->notes]]),
                'lines' => [],
            ],
            $record instanceof \App\Models\Employee => [
                'title' => $record->name, 'number' => $record->position, 'amount' => (float) $record->basic_pay + array_sum(array_column((array) $record->allowances, 'amount')),
                'status' => $record->is_active ? 'active' : 'off', 'status_label' => $record->is_active ? 'On the payroll' : 'Left', 'date' => $record->start_date?->toDateString(),
                'facts' => $f([['Pay to', $pay($record->payee, $record->pay_to)], ['Phone', $record->phone], ['Email', $record->email], ['Started', $record->start_date?->format('j M Y')], ['Left', $record->end_date?->format('j M Y')]]),
                'lines' => array_merge([['Basic pay', (float) $record->basic_pay]], array_map(fn ($a) => [$a['name'], (float) $a['amount']], (array) $record->allowances)),
            ],
            default => null,
        };
    }

    private function billEvents(SupplierInvoice $b): array
    {
        $v = $b->voucher;

        return [
            $this->event($b->created_at, $this->name($b->posted_by), "Entered the bill for {$this->money($b->amount)} from {$b->supplier?->name}", 'ri-file-list-2-line', 'warning', $b->supplier_ref ? "Their invoice {$b->supplier_ref}" : null, 1),
            $v ? $this->event($v->prepared_at ?? $v->created_at, $this->name($v->prepared_by), "Voucher {$v->number} made to pay it", 'ri-edit-line', 'primary', null, 2) : null,
            $v ? $this->event($v->paid_at ?? $v->paid_on, $this->name($v->paid_by), 'Paid', 'ri-hand-coin-line', 'success', null, 3) : null,
        ];
    }

    private function journalEvents(Journal $j): array
    {
        return [
            $this->event($j->posted_at ?? $j->created_at, $this->name($j->posted_by), "Posted {$j->number} for {$this->money($j->amount)}", 'ri-book-2-line', 'primary', $j->narration, 1),
            $j->reversed_by_id ? $this->event(Journal::find($j->reversed_by_id)?->created_at, $this->name(Journal::find($j->reversed_by_id)?->posted_by), 'Reversed', 'ri-arrow-go-back-line', 'danger', $j->reverse_reason, 2) : null,
        ];
    }

    private function giftEvents(\App\Models\Gift $g): array
    {
        return [
            $this->event($g->created_at, null, 'Started on the giving page'.($g->giver_name ? " by {$g->giver_name}" : ''), 'ri-hand-heart-line', 'primary', null, 1),
            $this->event($g->paid_at, null, "Paid {$this->money($g->amount)}".($g->provider_ref ? " - {$g->provider_ref}" : ''), 'ri-checkbox-circle-line', 'success', null, 2),
            in_array($g->status, ['failed', 'abandoned'], true) ? $this->event($g->updated_at, null, 'Not paid', 'ri-close-circle-line', 'danger', $g->result, 3) : null,
            $this->event($g->disputed_at, null, 'Disputed by the giver\'s bank', 'ri-error-warning-line', 'danger', null, 4),
            $this->event($g->refunded_at, null, "Refunded {$this->money($g->refunded_amount)}", 'ri-arrow-go-back-line', 'danger', null, 5),
        ];
    }

    private function supplierEvents(\App\Models\Supplier $s): array
    {
        $out = [$this->event($s->created_at, $this->name($s->created_by), "Added {$s->name} as a supplier", 'ri-store-2-line', 'primary', null, 1)];
        foreach (PurchaseOrder::where('supplier_id', $s->id)->latest('id')->limit(15)->get() as $o) {
            $out[] = $this->event($o->created_at, $this->name($o->issued_by), "Order {$o->number} for {$this->money($o->amount)}", 'ri-shopping-cart-2-line', 'primary', null, 2);
        }
        foreach (SupplierInvoice::where('supplier_id', $s->id)->latest('id')->limit(15)->get() as $b) {
            $out[] = $this->event($b->created_at, $this->name($b->posted_by), "Bill {$b->number} for {$this->money($b->amount)}", 'ri-file-list-2-line', 'warning', null, 3);
        }

        return $out;
    }

    private function employeeEvents(\App\Models\Employee $e): array
    {
        $out = [$this->event($e->created_at, $this->name($e->created_by), "Added {$e->name} to the payroll", 'ri-user-add-line', 'primary', null, 1)];
        foreach (\App\Models\Payslip::where('employee_id', $e->id)->with('run')->latest('id')->limit(24)->get() as $p) {
            $out[] = $this->event($p->created_at, null, 'Payslip for '.($p->run?->month ?? '').": {$this->money($p->net)} net", 'ri-file-user-line', 'success', null, 2);
        }

        return $out;
    }

    // ------------------------------------------------------------------ events

    private function event(mixed $at, ?string $who, string $text, string $icon, string $tone = 'primary', ?string $note = null, int $order = 0): ?array
    {
        if (! $at) {
            return null;
        }
        $iso = $at instanceof \DateTimeInterface ? $at->format(strlen((string) $at) > 10 ? \DateTimeInterface::ATOM : 'Y-m-d') : (string) $at;
        if ($at instanceof \Carbon\CarbonInterface) {
            $iso = $at->format('H:i:s') === '00:00:00' ? $at->toDateString() : $at->toIso8601String();
        }

        return ['at' => $iso, 'who' => $who, 'text' => $text, 'icon' => $icon, 'tone' => $tone, 'note' => $note, 'order' => $order];
    }

    private function name(?int $userId): ?string
    {
        return $userId ? User::find($userId)?->full_name : null;
    }

    private function money(mixed $n): string
    {
        return 'KES '.number_format((float) $n, 2);
    }

    private function voucherEvents(PaymentVoucher $v): array
    {
        $how = ['cash' => 'cash', 'mpesa' => 'M-Pesa', 'airtel' => 'Airtel Money', 'bank' => 'bank', 'cheque' => 'cheque'][$v->method] ?? $v->method;
        $engine = $this->approvalRequests($v)->isNotEmpty();

        return [
            $this->event($v->prepared_at ?? $v->created_at, $this->name($v->prepared_by), "Prepared the voucher for {$this->money($v->amount)} to {$v->payee_name}", 'ri-edit-line', 'primary', null, 1),
            // With the approval engine, its decisions tell the story; the columns only for older vouchers.
            $engine ? null : $this->event($v->authorised_at, $this->name($v->authorised_by), 'Authorised it', 'ri-shield-check-line', 'success', $v->authorise_note, 2),
            $engine ? null : $this->event($v->rejected_at, $this->name($v->rejected_by), 'Sent it back', 'ri-arrow-go-back-line', 'danger', $v->reject_reason, 3),
            $this->event($v->paid_at ?? $v->paid_on, $this->name($v->paid_by), 'Paid it'.($how ? " by {$how}" : '').($v->journal ? " - posted as {$v->journal->number}" : ''), 'ri-hand-coin-line', 'success', null, 4),
            $this->event($v->cancelled_at, $this->name($v->cancelled_by), 'Cancelled it', 'ri-close-circle-line', 'secondary', null, 5),
        ];
    }

    private function requisitionEvents(Requisition $r): array
    {
        $decided = ['approved' => ['Approved it', 'ri-check-line', 'success'], 'rejected' => ['Rejected it', 'ri-close-line', 'danger'], 'returned' => ['Sent it back for changes', 'ri-arrow-go-back-line', 'danger']];
        $out = [$this->event($r->created_at, $this->name($r->requested_by), "Asked for {$this->money($r->amount)}: {$r->purpose}", 'ri-hand-heart-line', 'primary', null, 1)];
        // Decisions made outside the approval engine (older requests) live on the record itself.
        if ($r->decided_at && ! $this->approvalRequests($r)->count()) {
            $d = $decided[in_array($r->status, ['rejected', 'returned'], true) ? $r->status : 'approved'];
            $out[] = $this->event($r->decided_at, $this->name($r->decided_by), $d[0], $d[1], $d[2], $r->decision_note, 2);
        }
        if ($r->status === 'cancelled') {
            $out[] = $this->event($r->updated_at, null, 'Cancelled', 'ri-close-circle-line', 'secondary', null, 9);
        }
        $advance = $r->kind === 'advance' ? StaffAdvance::where('requisition_id', $r->id)->first() : null;
        if ($advance) {
            $out[] = $this->event($advance->issued_on, $advance->holder_name, "Was given the advance of {$this->money($advance->amount)}", 'ri-wallet-3-line', 'warning', null, 5);
            if ($advance->status === 'retired') {
                $out[] = $this->event($advance->updated_at, $advance->holder_name, "Accounted for it: spent {$this->money($advance->spent)}, returned {$this->money($advance->returned)}", 'ri-receipt-2-line', 'success', null, 6);
            }
        }

        return $out;
    }

    private function payrollEvents(PayrollRun $p): array
    {
        $out = [$this->event($p->created_at, $this->name($p->prepared_by), "Started the payroll for {$p->month}", 'ri-calendar-line', 'primary', null, 1)];
        if ($this->approvalRequests($p)->isEmpty()) {
            $out[] = $this->event($p->approved_at, $this->name($p->approved_by), $p->status === 'returned' ? 'Sent it back' : 'Approved it', $p->status === 'returned' ? 'ri-arrow-go-back-line' : 'ri-check-line', $p->status === 'returned' ? 'danger' : 'success', $p->decision_note, 3);
        }
        foreach ($p->payments()->with('voucher')->get() as $pay) {
            $v = $pay->voucher;
            if ($v && $v->status === 'paid') {
                $out[] = $this->event($v->paid_at ?? $v->paid_on, $this->name($v->paid_by), 'Paid '.(['net' => 'net pay'][$pay->kind] ?? str_replace('_', ' ', $pay->kind))." - {$this->money($pay->amount)} ({$v->number})", 'ri-hand-coin-line', 'success', null, 30);
            }
        }

        return $out;
    }

    private function orderEvents(PurchaseOrder $o): array
    {
        $o->loadMissing(['deliveries', 'bills']);
        $out = [$this->event($o->created_at, $this->name($o->issued_by), "Ordered from {$o->supplier?->name} for {$this->money($o->amount)}", 'ri-shopping-cart-2-line', 'primary', null, 1)];
        foreach ($o->deliveries as $g) {
            $out[] = $this->event($g->created_at, $this->name($g->received_by), $g->status === 'undone' ? "Delivery {$g->number} was undone" : "Received the goods ({$g->number})", 'ri-truck-line', $g->status === 'undone' ? 'secondary' : 'success', $g->notes, 2);
        }
        foreach ($o->bills as $b) {
            $out[] = $this->event($b->created_at, $this->name($b->posted_by), "Entered the supplier's bill {$b->number} for {$this->money($b->amount)}", 'ri-file-list-2-line', 'warning', null, 3);
        }
        if ($o->ended_at) {
            $out[] = $this->event($o->ended_at, $this->name($o->ended_by), $o->status === 'cancelled' ? 'Cancelled the order' : 'Closed the order', 'ri-close-circle-line', 'secondary', $o->end_reason, 4);
        }

        return $out;
    }

    private function remittanceEvents(Remittance $r): array
    {
        return [
            $this->event($r->created_at, $this->name($r->created_by), 'Raised it: '.lcfirst(Remittance::KINDS[$r->kind])." - {$this->money($r->amount)} to {$r->to?->name}", 'ri-send-plane-line', 'primary', $r->purpose, 1),
            $this->event($r->sent_on, null, 'Sent'.($r->reference ? " - reference {$r->reference}" : ''), 'ri-upload-2-line', 'warning', null, 2),
            $this->event($r->queried_at, $this->name($r->queried_by), 'Queried it', 'ri-question-line', 'danger', $r->query_reason, 3),
            $r->answer ? $this->event($r->updated_at, null, 'Answered the query', 'ri-chat-check-line', 'primary', $r->answer, 4) : null,
            $this->event($r->confirmed_at, $this->name($r->confirmed_by), "Confirmed it was received by {$r->to?->name}", 'ri-checkbox-circle-line', 'success', null, 5),
        ];
    }

    private function collectionEvents(Collection $c): array
    {
        return [
            $this->event($c->created_at, $this->name($c->counted_by), "Counted {$this->money($c->total)} (cash {$this->money($c->cash_total)}, M-Pesa {$this->money($c->mpesa_total)})", 'ri-hand-coin-line', 'primary', null, 1),
            $c->status === 'returned' ? $this->event($c->updated_at, null, 'Sent back to recount', 'ri-arrow-go-back-line', 'danger', $c->return_reason, 2) : null,
            $this->event($c->confirmed_at, $this->name($c->confirmed_by), 'Confirmed the count - receipted in the books', 'ri-checkbox-circle-line', 'success', null, 3),
            $c->bankingJournal ? $this->event($c->bankingJournal->date, $this->name($c->bankingJournal->posted_by), "Banked {$this->money($c->bankingJournal->amount)} ({$c->bankingJournal->number})", 'ri-bank-line', 'success', null, 4) : null,
        ];
    }

    private function approvalRequests(Model $record)
    {
        return ApprovalRequest::where('subject_type', $record->getMorphClass())->where('subject_id', $record->getKey())->orderBy('id')->get();
    }

    /** The approval engine's events for every request about the record. */
    private function approvalEvents(Model $record): array
    {
        $requests = $this->approvalRequests($record);
        if ($requests->isEmpty()) {
            return [];
        }
        $out = [];
        $events = ApprovalEvent::whereIn('request_id', $requests->pluck('id'))->orderBy('id')->get();
        foreach ($events as $e) {
            $out[] = $this->approvalSentence($e);
        }

        return $out;
    }

    /** One approval event as a plain sentence (also for the Approvals board's activity). */
    public function approvalSentence(ApprovalEvent $e): ?array
    {
        $p = $e->payload ?? [];
        $who = $this->name($e->actor_id);

        return match ($e->type) {
            'submitted' => $this->event($e->created_at, $who, 'Sent it for approval'.(! empty($p['workflow']) ? " ({$p['workflow']})" : ''), 'ri-send-plane-line', 'primary', null, 10),
            'stage_opened' => $this->event($e->created_at, null, "Waiting on {$this->names($p['approvers'] ?? [])} - {$p['stage']}", 'ri-time-line', 'warning', null, 11),
            'decided' => $this->event($e->created_at, $who, ['approve' => 'Approved', 'approved' => 'Approved', 'reject' => 'Rejected', 'rejected' => 'Rejected', 'return' => 'Sent back', 'returned' => 'Sent back'][$p['decision'] ?? ''] ?? 'Decided', ($p['decision'] ?? '') === 'approve' || ($p['decision'] ?? '') === 'approved' ? 'ri-check-line' : 'ri-arrow-go-back-line', in_array($p['decision'] ?? '', ['approve', 'approved'], true) ? 'success' : 'danger', $p['comment'] ?? null, 12),
            'stage_blocked' => $this->event($e->created_at, null, "Stuck at {$p['stage']}: ".($p['reason'] ?? 'nobody holds the role'), 'ri-error-warning-line', 'danger', null, 13),
            'stage_skipped' => $this->event($e->created_at, null, "{$p['stage']} was not needed", 'ri-subtract-line', 'secondary', null, 13),
            'returned_to_stage' => $this->event($e->created_at, $who, "Sent back to {$p['stage']}", 'ri-arrow-go-back-line', 'danger', null, 13),
            'reminded' => $this->event($e->created_at, null, 'A reminder was sent', 'ri-notification-3-line', 'secondary', null, 14),
            'escalated', 'stage_escalated_empty' => $this->event($e->created_at, null, 'Passed up to the next person', 'ri-arrow-up-line', 'purple', null, 14),
            'cancelled' => $this->event($e->created_at, $who, 'Withdrew the request', 'ri-close-circle-line', 'secondary', null, 15),
            default => null, // approved / rejected / returned: the decision above says it
        };
    }

    private function names(array $ids): string
    {
        $names = User::whereIn('id', $ids)->get()->pluck('full_name')->filter()->all();

        return $names ? implode(', ', $names) : 'the approver';
    }

    private function fileEvents(Model $record): array
    {
        if (! method_exists($record, 'getMedia')) {
            return [];
        }

        return $record->getMedia('attachments')->map(fn ($m) => $this->event($m->created_at, null, "Attached {$m->name}", 'ri-attachment-2', 'secondary', null, 20))->all();
    }

    // ------------------------------------------------------------------- links

    private function link(string $type, ?Model $m, string $label): ?array
    {
        if (! $m) {
            return null;
        }
        $status = $m->status ?? null;
        $statuses = defined(get_class($m).'::STATUSES') ? $m::STATUSES : [];

        return [
            'type' => $type,
            'id' => $m->getKey(),
            'label' => $label,
            'number' => $m->number ?? $m->reference ?? ($m instanceof PayrollRun ? "Payroll {$m->month}" : ($m instanceof Collection ? $m->title : ($m->name ?? null))),
            'status' => $status,
            'status_label' => $statuses[$status] ?? ($status ? ucfirst(str_replace('_', ' ', $status)) : null),
            'amount' => isset($m->amount) ? (float) $m->amount : (isset($m->net) ? (float) $m->net : (isset($m->total) ? (float) $m->total : null)),
            'date' => ($m->date ?? $m->paid_on ?? $m->created_at)?->toDateString(),
            'opens' => in_array($type, ['voucher', 'requisition', 'payroll', 'order', 'remittance', 'collection', 'journal', ...self::SUMMARISED], true),
        ];
    }

    private function links(Model $record): array
    {
        return match (true) {
            $record instanceof PaymentVoucher => $this->voucherLinks($record),
            $record instanceof Requisition => $this->requisitionLinks($record),
            $record instanceof PurchaseOrder => $this->orderLinks($record),
            $record instanceof PayrollRun => [
                ...$record->payments()->with('voucher.journal')->get()->flatMap(fn ($p) => [$this->link('voucher', $p->voucher, 'Payment voucher - '.str_replace('_', ' ', $p->kind)), $this->link('journal', $p->voucher?->journal, 'Paid in the books')])->all(),
                $this->link('journal', Journal::find($record->journal_id), 'Payroll in the books'),
            ],
            $record instanceof Remittance => [
                $this->link('voucher', $record->voucher, 'Payment voucher'),
                $this->link('journal', Journal::find($record->sent_journal_id), 'Sent - in our books'),
                $this->link('journal', Journal::find($record->received_journal_id), 'Received - in their books'),
            ],
            $record instanceof Collection => [
                $this->link('journal', $record->journal, 'Receipt in the books'),
                $this->link('journal', $record->bankingJournal, 'Banked'),
            ],
            $record instanceof SupplierInvoice => [
                $this->link('order', $record->order, 'Purchase order'),
                $this->link('requisition', $record->order?->requisition, 'Requisition'),
                $this->link('supplier', $record->supplier, 'Supplier'),
                $this->link('journal', Journal::find($record->journal_id), 'Bill in the books'),
                $this->link('voucher', $record->voucher, 'Payment voucher'),
                $this->link('journal', $record->voucher?->journal, 'Payment in the books'),
            ],
            $record instanceof Journal => [
                $this->link('journal', $record->reversed_by_id ? Journal::find($record->reversed_by_id) : null, 'Its reversal'),
                $this->link('journal', $record->reverses_id ? Journal::find($record->reverses_id) : null, 'What it reverses'),
                $record->source_type === 'gift' ? $this->link('gift', \App\Models\Gift::find($record->source_id), 'Online gift') : null,
                $record->source_type === 'collection' ? $this->link('collection', Collection::find($record->source_id), 'Collection') : null,
                $record->source_type === 'payment_voucher' ? $this->link('voucher', PaymentVoucher::find($record->source_id), 'Payment voucher') : null,
                $record->source_type === 'supplier_invoice' ? $this->link('bill', SupplierInvoice::find($record->source_id), "Supplier's bill") : null,
            ],
            $record instanceof \App\Models\Gift => [
                $this->link('receipt', Journal::find($record->journal_id), 'Receipt in our books'),
                $this->link('receipt', Journal::find($record->diocese_journal_id), 'In the diocese\'s books'),
                $this->link('remittance', $record->remittance_id ? Remittance::find($record->remittance_id) : null, 'Diocese share'),
            ],
            $record instanceof \App\Models\Supplier => [
                ...PurchaseOrder::where('supplier_id', $record->id)->latest('id')->limit(10)->get()->map(fn ($o) => $this->link('order', $o, 'Purchase order'))->all(),
                ...SupplierInvoice::where('supplier_id', $record->id)->latest('id')->limit(10)->get()->map(fn ($b) => $this->link('bill', $b, "Supplier's bill"))->all(),
            ],
            $record instanceof \App\Models\Employee => \App\Models\Payslip::where('employee_id', $record->id)->with('run')->latest('id')->limit(12)->get()
                ->map(fn ($p) => $this->link('payroll', $p->run, 'Payroll '.($p->run?->month ?? '')))->all(),
            default => [],
        };
    }

    private function voucherLinks(PaymentVoucher $v): array
    {
        $bill = $v->supplier_invoice_id ? SupplierInvoice::find($v->supplier_invoice_id) : null;
        $order = $bill?->order ?? $v->requisition?->order;
        $run = $v->payroll_payment_id ? PayrollRun::find(\App\Models\PayrollPayment::find($v->payroll_payment_id)?->payroll_run_id) : null;

        return [
            $this->link('requisition', $v->requisition ?? $order?->requisition, 'Requisition'),
            $this->link('order', $order, 'Purchase order'),
            $this->link('bill', $bill, "Supplier's bill"),
            $this->link('payroll', $run, 'Payroll'),
            $this->link('remittance', $v->remittance_id ? Remittance::find($v->remittance_id) : null, 'Remittance'),
            $this->link('advance', StaffAdvance::where('payment_voucher_id', $v->id)->first(), 'Advance'),
            $this->link('journal', $v->journal, 'Payment in the books'),
        ];
    }

    private function requisitionLinks(Requisition $r): array
    {
        $order = $r->order;
        $out = [$this->link('order', $order, 'Purchase order')];
        if ($order) {
            foreach ($order->deliveries as $g) {
                $out[] = $this->link('delivery', $g, 'Delivery');
            }
            foreach ($order->bills as $b) {
                $out[] = $this->link('bill', $b, "Supplier's bill");
                $out[] = $this->link('voucher', $b->voucher, 'Payment voucher');
                $out[] = $this->link('journal', $b->voucher?->journal, 'Payment in the books');
            }
        }
        $voucher = $r->voucher;
        $out[] = $this->link('voucher', $voucher, 'Payment voucher');
        $out[] = $this->link('advance', StaffAdvance::where('requisition_id', $r->id)->first(), 'Advance');
        $out[] = $this->link('journal', $voucher?->journal, 'Payment in the books');

        return $out;
    }

    private function orderLinks(PurchaseOrder $o): array
    {
        $out = [$this->link('requisition', $o->requisition, 'Requisition')];
        foreach ($o->deliveries as $g) {
            $out[] = $this->link('delivery', $g, 'Delivery');
        }
        foreach ($o->bills as $b) {
            $out[] = $this->link('bill', $b, "Supplier's bill");
            $out[] = $this->link('voucher', $b->voucher, 'Payment voucher');
            $out[] = $this->link('journal', $b->voucher?->journal, 'Payment in the books');
        }

        return $out;
    }
}
