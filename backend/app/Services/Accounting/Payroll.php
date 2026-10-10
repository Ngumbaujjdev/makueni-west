<?php

namespace App\Services\Accounting;

use App\Approval\Services\ApprovalService;
use App\Approval\Services\Inbox;
use App\Models\Employee;
use App\Models\Journal;
use App\Models\PaymentVoucher;
use App\Models\PayrollPayment;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Territory;
use App\Models\User;
use App\Support\AccountingAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Payroll (docs/specs/accounting-spec.md, A7): the people a place pays and
 * a monthly run - pay (basic + allowances) less any other deduction (a SACCO,
 * a loan); the churches don't deduct PAYE, NSSF, SHIF or the Housing Levy.
 * Approved through the engine, which posts it (Dr salaries / Cr net pay
 * payable, Cr deductions payable for what was held back), then the staff are
 * paid by one voucher already authorised.
 */
final class Payroll
{
    public function __construct(private Ledger $ledger, private Chart $chart, private BudgetBridge $bridge, private PaymentVouchers $vouchers, private ApprovalService $engine) {}

    // ------------------------------------------------------------ people

    /** Add or change someone a place pays - through Staff (docs/specs/hr-spec.md), the one record. */
    public function saveEmployee(Territory $place, User $user, array $data, ?Employee $e = null): Employee
    {
        return app(\App\Services\HR\Staff::class)->save($place, $user, $data, $e);
    }

    // ------------------------------------------------------------ the run

    /** Start a month: a draft with a payslip for everyone paid in it. */
    public function start(Territory $place, User $user, string $month): PayrollRun
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) || $month > now()->addMonth()->format('Y-m')) {
            throw ValidationException::withMessages(['month' => ['Pick a month up to next month.']]);
        }
        if (PayrollRun::where('territory_id', $place->id)->where('month', $month)->where('status', '!=', 'cancelled')->exists()) {
            throw ValidationException::withMessages(['month' => ['There is already a payroll for '.date('F Y', strtotime("{$month}-01")).'.']]);
        }
        $people = $this->paidHere($place, $month);
        if ($people->isEmpty()) {
            throw ValidationException::withMessages(['month' => ['Nobody is on the payroll for that month - add the people you pay first.']]);
        }

        return DB::transaction(function () use ($place, $user, $month, $people) {
            $run = PayrollRun::create(['territory_id' => $place->id, 'month' => $month, 'status' => 'draft', 'prepared_by' => $user->id]);
            foreach ($people as $e) {
                $run->payslips()->create($this->slipFor($e));
            }
            $this->totals($run);

            return $run->fresh('payslips');
        });
    }

    /**
     * Everyone this place pays for the month: posted here during it - the
     * place they were last posted to pays the whole month - and not left before it.
     */
    private function paidHere(Territory $place, string $month)
    {
        $start = "{$month}-01";
        $end = date('Y-m-t', strtotime($start));

        return Employee::where(fn ($q) => $q->where('territory_id', $place->id)
            ->orWhereHas('postings', fn ($p) => $p->where('territory_id', $place->id)->where('from_date', '<=', $end)->where(fn ($w) => $w->whereNull('to_date')->orWhere('to_date', '>=', $start))))
            ->orderBy('name')->get()->filter(fn ($e) => $e->paidIn($month) && $e->payingPlaceIn($month) === (int) $place->id)->values();
    }

    /** A payslip from what the person is paid now (keeping any other deduction already on it). */
    private function slipFor(Employee $e, float $other = 0, ?string $otherNote = null): array
    {
        return $this->worked([
            'employee_id' => $e->id, 'name' => $e->name, 'position' => $e->position, 'pay_method' => $e->pay_method, 'pay_to' => $e->pay_to, 'payee' => $e->payee,
            'basic' => (float) $e->basic_pay, 'allowances' => $e->allowances ?? [], 'other' => $other, 'other_note' => $otherNote,
        ]);
    }

    /** Pay = basic + allowances; net = pay less any other deduction. */
    private function worked(array $s): array
    {
        $gross = round((float) $s['basic'] + array_sum(array_map(fn ($a) => (float) $a['amount'], $s['allowances'] ?? [])), 2);
        $other = round(max((float) $s['other'], 0), 2);
        if ($other > $gross) {
            throw ValidationException::withMessages(['other' => ["{$s['name']}'s deduction comes to more than their pay."]]);
        }

        return $s + ['gross' => $gross, 'total_deductions' => $other, 'net' => round($gross - $other, 2)];
    }

    /** Change one person's pay in a draft: basic, allowances, or the other deduction. */
    public function updateSlip(PayrollRun $run, Payslip $slip, array $data): Payslip
    {
        $this->assertDraft($run);
        $allowances = array_values(array_filter(array_map(fn ($a) => ['name' => mb_substr(trim((string) ($a['name'] ?? '')), 0, 60) ?: 'Allowance', 'amount' => round(max((float) ($a['amount'] ?? 0), 0), 2)], $data['allowances'] ?? $slip->allowances ?? []), fn ($a) => $a['amount'] > 0));
        $fields = $this->worked([
            'name' => $slip->name,
            'basic' => round(max((float) ($data['basic'] ?? $slip->basic), 0), 2),
            'allowances' => $allowances,
            'other' => round(max((float) ($data['other'] ?? $slip->other), 0), 2),
            'other_note' => $this->clean($data['other_note'] ?? $slip->other_note, 150),
        ]);
        unset($fields['name']);
        $slip->update($fields);
        $this->totals($run);

        return $slip->fresh();
    }

    public function addSlip(PayrollRun $run, int $employeeId): Payslip
    {
        $this->assertDraft($run);
        $e = $this->paidHere(Territory::findOrFail($run->territory_id), $run->month)->firstWhere('id', $employeeId);
        if (! $e) {
            throw ValidationException::withMessages(['employee_id' => ['Pick someone on this place\'s payroll.']]);
        }
        if ($run->payslips()->where('employee_id', $e->id)->exists()) {
            throw ValidationException::withMessages(['employee_id' => ["{$e->name} is already in this run."]]);
        }
        $slip = $run->payslips()->create($this->slipFor($e));
        $this->totals($run);

        return $slip;
    }

    public function removeSlip(PayrollRun $run, Payslip $slip): void
    {
        $this->assertDraft($run);
        $slip->delete();
        $this->totals($run);
    }

    /** Read everyone's pay again from what they are paid now (their other deduction stays). */
    public function recalculate(PayrollRun $run): PayrollRun
    {
        $this->assertDraft($run);
        DB::transaction(function () use ($run) {
            foreach ($run->payslips()->with('employee')->get() as $slip) {
                $slip->update($this->slipFor($slip->employee, (float) $slip->other, $slip->other_note));
            }
            $this->totals($run);
        });

        return $run->fresh('payslips');
    }

    private function totals(PayrollRun $run): void
    {
        $s = $run->payslips()->get();
        $run->update([
            'gross' => round($s->sum(fn ($p) => (float) $p->gross), 2),
            'deductions' => round($s->sum(fn ($p) => (float) $p->total_deductions), 2),
            'net' => round($s->sum(fn ($p) => (float) $p->net), 2),
        ]);
    }

    // ------------------------------------------------------------ approval

    public function submit(PayrollRun $run, User $user): PayrollRun
    {
        $this->assertDraft($run);
        if (! $run->payslips()->exists() || (float) $run->gross <= 0) {
            throw ValidationException::withMessages(['run' => ['There is nobody to pay in it.']]);
        }

        return DB::transaction(function () use ($run, $user) {
            $run->update(['status' => 'submitted', 'decision_note' => null]);
            $this->engine->route($run->fresh(), $user);

            return $run->fresh('payslips');
        });
    }

    /** Through the engine when it has a request; with no rule, anyone who authorises payments here - never who prepared it. */
    public function decide(PayrollRun $run, User $user, string $decision, ?string $comment): PayrollRun
    {
        if ($request = $this->engine->current($run)) {
            $turn = app(Inbox::class)->myTurn($user, $request);
            if (! $turn) {
                throw ValidationException::withMessages(['run' => [(int) $run->prepared_by === (int) $user->id ? 'You prepared it - someone else must approve it.' : 'It waits for someone else\'s approval.']]);
            }
            $this->engine->decide($turn, $user, $decision, $comment);

            return $run->fresh('payslips');
        }
        if ($run->status !== 'submitted') {
            throw ValidationException::withMessages(['status' => ['It isn\'t waiting for approval.']]);
        }
        if (! AccountingAccess::can($user, Territory::findOrFail($run->territory_id), 'authorise')) {
            throw ValidationException::withMessages(['run' => ['Your role can\'t approve payroll here.']]);
        }
        if ((int) $run->prepared_by === (int) $user->id) {
            throw ValidationException::withMessages(['run' => ['You prepared it - someone else must approve it.']]);
        }
        if (in_array($decision, ['reject', 'return'], true) && ! trim((string) $comment)) {
            throw ValidationException::withMessages(['comment' => ['Say why.']]);
        }
        DB::transaction(fn () => $this->outcome($run, ['approve' => 'approved', 'reject' => 'rejected', 'return' => 'returned'][$decision], $user, $comment));

        return $run->fresh('payslips');
    }

    /** The decision: approved is posted at once; rejected or returned is a draft again. */
    public function outcome(PayrollRun $run, string $outcome, ?User $by, ?string $comment): void
    {
        if ($outcome === 'approved') {
            $this->post($run, $by);
            $run->update(['status' => 'posted', 'approved_by' => $by?->id, 'approved_at' => now(), 'decision_note' => $comment ? mb_substr($comment, 0, 255) : null]);
        } elseif (in_array($outcome, ['rejected', 'returned'], true)) {
            $run->update(['status' => 'returned', 'decision_note' => $comment ? mb_substr($comment, 0, 255) : null]);
        } elseif ($outcome === 'cancelled' && $run->status === 'submitted') {
            $run->update(['status' => 'draft']);
        }
    }

    /** Dr salaries (on the salaries budget line) / Cr net pay payable / Cr deductions payable for what was held back. */
    private function post(PayrollRun $run, ?User $by): Journal
    {
        $place = Territory::findOrFail($run->territory_id);
        $salaries = $this->standard('5000');
        $date = min(date('Y-m-t', strtotime("{$run->month}-01")), now()->toDateString());
        $lines = [
            ['account_id' => $salaries->id, 'debit' => (float) $run->gross, 'budget_line_id' => $this->chart->budgetLineFor($place, $salaries->id)?->id, 'memo' => 'Pay'],
            ['account_id' => $this->chart->account('net_pay')->id, 'credit' => (float) $run->net, 'memo' => 'Net pay'],
        ];
        if ((float) $run->deductions > 0) {
            $lines[] = ['account_id' => $this->chart->account('payroll_deductions')->id, 'credit' => (float) $run->deductions, 'memo' => 'Held from pay (SACCO, loans)'];
        }
        $journal = $this->ledger->post($place, [
            'doc_type' => 'payroll', 'date' => $date, 'narration' => 'Payroll for '.$run->label(), 'source_type' => 'payroll_run', 'source_id' => $run->id,
        ], $lines, $by);
        $this->bridge->journalPosted($journal, $by);
        $run->update(['journal_id' => $journal->id]);

        return $journal;
    }

    public function cancel(PayrollRun $run, User $user): PayrollRun
    {
        if (! in_array($run->status, ['draft', 'returned', 'submitted'], true)) {
            throw ValidationException::withMessages(['run' => ['A posted payroll can\'t be cancelled.']]);
        }

        return DB::transaction(function () use ($run, $user) {
            if ($pending = $this->engine->current($run)) {
                $this->engine->cancel($pending, $user);
            }
            $run->update(['status' => 'cancelled']);

            return $run->fresh('payslips');
        });
    }

    // ------------------------------------------------------------ paying

    /** Pay the staff: one voucher, a line per person - already authorised by whoever approved the run. */
    public function pay(PayrollRun $run, User $user, int $payFromAccountId): PaymentVoucher
    {
        return DB::transaction(function () use ($run, $user, $payFromAccountId) {
            $run = PayrollRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if (! in_array($run->status, ['posted', 'paid'], true)) {
                throw ValidationException::withMessages(['run' => ['Only an approved payroll is paid.']]);
            }
            if ($this->openPayment($run)) {
                throw ValidationException::withMessages(['run' => ['The staff already have a voucher for this payroll.']]);
            }
            if ((float) $run->net <= 0) {
                throw ValidationException::withMessages(['run' => ['There is nothing to pay.']]);
            }
            $place = Territory::findOrFail($run->territory_id);
            $payment = PayrollPayment::create(['payroll_run_id' => $run->id, 'kind' => 'net', 'amount' => (float) $run->net]);
            $account = $this->chart->account('net_pay');
            $pv = $this->vouchers->prepareAuthorised($place, $user, [
                'date' => now()->toDateString(),
                'payee_name' => 'Staff - payroll '.$run->label(),
                'pay_from_account_id' => $payFromAccountId,
                'narration' => 'Net pay - payroll for '.$run->label(),
                'purpose' => 'payroll',
                'payroll_payment_id' => $payment->id,
                'lines' => $run->payslips()->where('net', '>', 0)->get()->map(fn ($p) => ['account_id' => $account->id, 'amount' => (float) $p->net, 'description' => mb_substr($p->name.($p->pay_to ? ' - '.Employee::METHODS[$p->pay_method].' '.$p->pay_to : ''), 0, 255)])->all(),
            ], $run->approved_by, 'Payroll for '.$run->label().', approved');
            $payment->update(['payment_voucher_id' => $pv->id]);

            return $pv;
        });
    }

    /** The staff's voucher, unless it was cancelled. */
    public function openPayment(PayrollRun $run): ?PayrollPayment
    {
        return PayrollPayment::with('voucher')->where('payroll_run_id', $run->id)
            ->whereHas('voucher', fn ($q) => $q->where('status', '!=', 'cancelled'))->first();
    }

    /** Its voucher was paid, its payment reversed or the voucher cancelled: the run is paid while that voucher is. */
    public function voucherChanged(PaymentVoucher $pv): void
    {
        $payment = PayrollPayment::find($pv->payroll_payment_id);
        if (! $payment) {
            return;
        }
        if ($pv->status === 'cancelled') {
            $payment->delete();
        }
        $run = PayrollRun::find($payment->payroll_run_id);
        if ($run && in_array($run->status, ['posted', 'paid'], true)) {
            $run->update(['status' => $pv->status === 'paid' ? 'paid' : 'posted']);
        }
    }

    /** Each person's payment reference (e.g. the M-Pesa code), once net pay is paid. */
    public function references(PayrollRun $run, array $refs): void
    {
        if (! in_array($run->status, ['posted', 'paid'], true)) {
            throw ValidationException::withMessages(['run' => ['References are recorded once it is paid.']]);
        }
        foreach ($run->payslips()->get() as $p) {
            if (array_key_exists($p->id, $refs)) {
                $p->update(['reference' => $this->clean($refs[$p->id], 60)]);
            }
        }
    }

    // ------------------------------------------------------------ helpers

    private function assertDraft(PayrollRun $run): void
    {
        if (! in_array($run->status, ['draft', 'returned'], true)) {
            throw ValidationException::withMessages(['run' => ['Only a draft payroll can be changed.']]);
        }
    }

    private function standard(string $code): \App\Models\AccountingAccount
    {
        $this->chart->ensureStandard();

        return \App\Models\AccountingAccount::whereNull('territory_id')->where('code', $code)->firstOrFail();
    }

    private function clean(mixed $v, int $max): ?string
    {
        $v = trim((string) ($v ?? ''));

        return $v === '' ? null : mb_substr($v, 0, $max);
    }
}
