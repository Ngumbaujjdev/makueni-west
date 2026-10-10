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
 * Payroll (docs/specs/accounting-spec.md, A7): the people a place pays, a
 * monthly run worked out with the statutory rates in Settings, approved
 * through the engine - which posts it (Dr salaries / Cr net pay payable, Cr
 * deductions payable) - then net pay paid to the staff and what was withheld
 * paid to each authority, each by a voucher already authorised.
 */
final class Payroll
{
    public function __construct(private PayrollCalculator $calc, private Ledger $ledger, private Chart $chart, private BudgetBridge $bridge, private PaymentVouchers $vouchers, private ApprovalService $engine) {}

    // ------------------------------------------------------------ people

    public function saveEmployee(Territory $place, User $user, array $data, ?Employee $e = null): Employee
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw ValidationException::withMessages(['name' => ['Who is it?']]);
        }
        $allowances = [];
        foreach ($data['allowances'] ?? [] as $i => $a) {
            $label = trim((string) ($a['name'] ?? ''));
            $amount = round((float) ($a['amount'] ?? 0), 2);
            if ($label === '' && $amount <= 0) {
                continue;
            }
            if ($label === '' || $amount <= 0) {
                throw ValidationException::withMessages(["allowances.{$i}" => ['Give each allowance a name and an amount.']]);
            }
            $allowances[] = ['name' => mb_substr($label, 0, 60), 'amount' => $amount];
        }
        if (! empty($data['start_date']) && ! empty($data['end_date']) && $data['end_date'] < $data['start_date']) {
            throw ValidationException::withMessages(['end_date' => ['They can\'t leave before they started.']]);
        }
        $fields = [
            'name' => mb_substr($name, 0, 150),
            'phone' => $this->clean($data['phone'] ?? null, 30),
            'email' => $this->clean($data['email'] ?? null, 150),
            'position' => $this->clean($data['position'] ?? null, 100),
            'start_date' => $data['start_date'] ?? null,
            'end_date' => $data['end_date'] ?? null,
            'pay_method' => in_array($data['pay_method'] ?? 'mpesa', array_keys(Employee::METHODS), true) ? $data['pay_method'] : 'mpesa',
            'pay_to' => $this->clean($data['pay_to'] ?? null, 150),
            'basic_pay' => round(max((float) ($data['basic_pay'] ?? 0), 0), 2),
            'allowances' => $allowances,
            'statutory' => (bool) ($data['statutory'] ?? true),
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : ($e?->is_active ?? true),
        ];
        // Personal numbers: a value replaces the saved one; left blank, the saved one stays.
        foreach (Employee::PRIVATE as $key) {
            if (($v = $this->clean($data[$key] ?? null, 40)) !== null) {
                $fields[$key] = strtoupper($v);
            }
        }
        if ((float) $fields['basic_pay'] + array_sum(array_column($allowances, 'amount')) <= 0) {
            throw ValidationException::withMessages(['basic_pay' => ['Enter what they are paid a month.']]);
        }
        if ($e) {
            $e->update($fields);

            return $e->fresh();
        }

        return Employee::create($fields + ['territory_id' => $place->id, 'created_by' => $user->id]);
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
        $people = Employee::where('territory_id', $place->id)->orderBy('name')->get()->filter(fn ($e) => $e->paidIn($month));
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

    /** A payslip from what the person is paid now (keeping any other deduction already on it). */
    private function slipFor(Employee $e, float $other = 0, ?string $otherNote = null): array
    {
        return $this->worked([
            'employee_id' => $e->id, 'name' => $e->name, 'position' => $e->position, 'pay_method' => $e->pay_method, 'pay_to' => $e->pay_to,
            'statutory' => $e->statutory, 'basic' => (float) $e->basic_pay, 'allowances' => $e->allowances ?? [], 'other' => $other, 'other_note' => $otherNote,
        ]);
    }

    /** The statutory figures and totals worked out (or, with $overrides, typed over). */
    private function worked(array $s, ?array $overrides = null): array
    {
        $gross = round((float) $s['basic'] + array_sum(array_map(fn ($a) => (float) $a['amount'], $s['allowances'] ?? [])), 2);
        $c = $this->calc->compute($gross, (bool) $s['statutory']);
        $manual = false;
        foreach (['nssf', 'shif', 'ahl', 'paye'] as $k) {
            if ($overrides !== null && array_key_exists($k, $overrides) && $overrides[$k] !== null && $overrides[$k] !== '' && round((float) $overrides[$k], 2) !== round($c[$k], 2)) {
                $c[$k] = round(max((float) $overrides[$k], 0), 2);
                $manual = true;
            }
        }
        $total = round($c['nssf'] + $c['shif'] + $c['ahl'] + $c['paye'] + (float) $s['other'], 2);
        if ($total > $gross) {
            throw ValidationException::withMessages(['other' => ["{$s['name']}'s deductions come to more than their pay."]]);
        }

        return $s + [
            'gross' => $gross, 'nssf' => $c['nssf'], 'shif' => $c['shif'], 'ahl' => $c['ahl'], 'taxable' => $c['taxable'], 'paye' => $c['paye'],
            'total_deductions' => $total, 'net' => round($gross - $total, 2), 'employer_nssf' => $c['employer_nssf'], 'employer_ahl' => $c['employer_ahl'], 'manual' => $manual,
        ];
    }

    /** Change one person's pay in a draft: basic, allowances, other deductions, or a statutory figure typed over. */
    public function updateSlip(PayrollRun $run, Payslip $slip, array $data): Payslip
    {
        $this->assertDraft($run);
        $allowances = array_values(array_filter(array_map(fn ($a) => ['name' => mb_substr(trim((string) ($a['name'] ?? '')), 0, 60) ?: 'Allowance', 'amount' => round(max((float) ($a['amount'] ?? 0), 0), 2)], $data['allowances'] ?? $slip->allowances ?? []), fn ($a) => $a['amount'] > 0));
        $fields = $this->worked([
            'name' => $slip->name, 'statutory' => $slip->statutory,
            'basic' => round(max((float) ($data['basic'] ?? $slip->basic), 0), 2),
            'allowances' => $allowances,
            'other' => round(max((float) ($data['other'] ?? $slip->other), 0), 2),
            'other_note' => $this->clean($data['other_note'] ?? $slip->other_note, 150),
        ], $data['overrides'] ?? null);
        unset($fields['name'], $fields['statutory']);
        $slip->update($fields);
        $this->totals($run);

        return $slip->fresh();
    }

    public function addSlip(PayrollRun $run, int $employeeId): Payslip
    {
        $this->assertDraft($run);
        $e = Employee::where('territory_id', $run->territory_id)->find($employeeId);
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

    /** Put the worked-out figures back for everyone (what they are paid now, today's rates). */
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
            'employer' => round($s->sum(fn ($p) => (float) $p->employer_nssf + (float) $p->employer_ahl), 2),
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

    /** Dr salaries (gross + the employer's share) / Cr net pay payable / Cr deductions payable. */
    private function post(PayrollRun $run, ?User $by): Journal
    {
        $place = Territory::findOrFail($run->territory_id);
        $salaries = $this->standard('5000');
        $line = $this->chart->budgetLineFor($place, $salaries->id)?->id;
        $date = min(date('Y-m-t', strtotime("{$run->month}-01")), now()->toDateString());
        $employer = round((float) $run->employer, 2);
        $lines = [['account_id' => $salaries->id, 'debit' => (float) $run->gross, 'budget_line_id' => $line, 'memo' => 'Gross pay']];
        if ($employer > 0) {
            $lines[] = ['account_id' => $salaries->id, 'debit' => $employer, 'budget_line_id' => $line, 'memo' => 'Employer NSSF and Housing Levy'];
        }
        $lines[] = ['account_id' => $this->chart->account('net_pay')->id, 'credit' => (float) $run->net, 'memo' => 'Net pay'];
        if ((float) $run->deductions + $employer > 0) {
            $lines[] = ['account_id' => $this->chart->account('payroll_deductions')->id, 'credit' => round((float) $run->deductions + $employer, 2), 'memo' => 'PAYE, NSSF, SHIF, Housing Levy and other deductions'];
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

    /** What each payment of a run comes to: net, and each authority (both shares where the employer pays too). @return array<string, float> */
    public function amounts(PayrollRun $run): array
    {
        $s = $run->payslips()->get();
        $sum = fn ($f) => round($s->sum($f), 2);

        return [
            'net' => $sum(fn ($p) => (float) $p->net),
            'paye' => $sum(fn ($p) => (float) $p->paye),
            'nssf' => $sum(fn ($p) => (float) $p->nssf + (float) $p->employer_nssf),
            'shif' => $sum(fn ($p) => (float) $p->shif),
            'ahl' => $sum(fn ($p) => (float) $p->ahl + (float) $p->employer_ahl),
        ];
    }

    /** Pay net pay (one voucher, a line per person) or remit one authority's deductions - a voucher already authorised. */
    public function pay(PayrollRun $run, User $user, string $kind, int $payFromAccountId): PaymentVoucher
    {
        if (! array_key_exists($kind, PayrollPayment::KINDS)) {
            throw ValidationException::withMessages(['kind' => ['Pick what is paid.']]);
        }

        return DB::transaction(function () use ($run, $user, $kind, $payFromAccountId) {
            $run = PayrollRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if (! in_array($run->status, ['posted', 'paid'], true)) {
                throw ValidationException::withMessages(['run' => ['Only an approved payroll is paid.']]);
            }
            if ($this->openPayment($run, $kind)) {
                throw ValidationException::withMessages(['kind' => [PayrollPayment::KINDS[$kind][0].' already has a voucher.']]);
            }
            $amount = $this->amounts($run)[$kind];
            if ($amount <= 0) {
                throw ValidationException::withMessages(['kind' => ['There is nothing to pay for '.PayrollPayment::KINDS[$kind][0].'.']]);
            }
            $place = Territory::findOrFail($run->territory_id);
            $payment = PayrollPayment::create(['payroll_run_id' => $run->id, 'kind' => $kind, 'amount' => $amount]);
            $account = $kind === 'net' ? $this->chart->account('net_pay') : $this->chart->account('payroll_deductions');
            $lines = $kind === 'net'
                ? $run->payslips()->where('net', '>', 0)->get()->map(fn ($p) => ['account_id' => $account->id, 'amount' => (float) $p->net, 'description' => mb_substr($p->name.($p->pay_to ? ' - '.Employee::METHODS[$p->pay_method].' '.$p->pay_to : ''), 0, 255)])->all()
                : [['account_id' => $account->id, 'amount' => $amount, 'description' => PayrollPayment::KINDS[$kind][0].' for '.$run->label()]];
            $pv = $this->vouchers->prepareAuthorised($place, $user, [
                'date' => now()->toDateString(),
                'payee_name' => $kind === 'net' ? 'Staff - payroll '.$run->label() : PayrollPayment::KINDS[$kind][1],
                'pay_from_account_id' => $payFromAccountId,
                'narration' => ($kind === 'net' ? 'Net pay' : PayrollPayment::KINDS[$kind][0]).' - payroll for '.$run->label(),
                'purpose' => 'payroll',
                'payroll_payment_id' => $payment->id,
                'lines' => $lines,
            ], $run->approved_by, 'Payroll for '.$run->label().', approved');
            $payment->update(['payment_voucher_id' => $pv->id]);

            return $pv;
        });
    }

    private function openPayment(PayrollRun $run, string $kind): ?PayrollPayment
    {
        return PayrollPayment::where('payroll_run_id', $run->id)->where('kind', $kind)
            ->whereHas('voucher', fn ($q) => $q->where('status', '!=', 'cancelled'))->first();
    }

    /** A voucher of a run was paid, its payment reversed or cancelled: the run is paid once net pay and every authority are. */
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
        if (! $run || ! in_array($run->status, ['posted', 'paid'], true)) {
            return;
        }
        $paid = collect($this->amounts($run))->every(fn ($amount, $kind) => $amount <= 0
            || PayrollPayment::where('payroll_run_id', $run->id)->where('kind', $kind)->whereHas('voucher', fn ($q) => $q->where('status', 'paid'))->exists());
        $run->update(['status' => $paid ? 'paid' : 'posted']);
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
