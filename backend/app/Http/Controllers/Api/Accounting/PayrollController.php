<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Approval\Services\ApprovalService;
use App\Approval\Services\Inbox;
use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Territory;
use App\Services\Accounting\Chart;
use App\Services\Accounting\Payroll;
use App\Support\AccountingAccess;
use App\Support\PlaceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Payroll (docs/specs/accounting-spec.md, A7). Salaries are private: only
 * whoever runs payroll (payroll.manage) and those given payroll.read see it,
 * plus whoever a run is waiting on through the approval engine. ID numbers
 * and KRA PINs leave the API masked, always.
 */
class PayrollController extends AccountingBase
{
    public function __construct(private Payroll $payroll, private Chart $chart, private ApprovalService $engine, private Inbox $inbox) {}

    /** GET /accounting/payroll - the people and the runs. */
    public function index(Request $request): JsonResponse
    {
        $place = $this->placeFor($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $runs = PayrollRun::where('territory_id', $place->id)->orderByDesc('month')->orderByDesc('id')->limit(60)->get();

        return $this->ok([
            'place' => $this->placeInfo($place),
            'can' => $this->can($request, $place),
            'employees' => Employee::where('territory_id', $place->id)->orderByDesc('is_active')->orderBy('name')->get()->map(fn ($e) => $this->presentEmployee($e))->values(),
            'runs' => $runs->map(fn ($r) => $this->presentRun($r, $request->user()))->values(),
            'cash' => $this->chart->cashAccounts($place)->map(fn ($a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'cash_kind' => $a->cash_kind])->values(),
            'next_month' => $this->nextMonth($place),
        ]);
    }

    /** POST · PUT /accounting/payroll/employees[/{id}] */
    public function saveEmployee(Request $request, ?int $id = null): JsonResponse
    {
        $place = $this->placeFor($request, true);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $e = $id ? Employee::where('territory_id', $place->id)->find($id) : null;
        if ($id && ! $e) {
            return $this->notFound('That person isn\'t on this payroll.');
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'position' => ['nullable', 'string', 'max:100'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'pay_method' => ['nullable', 'in:'.implode(',', array_keys(\App\Support\PayTo::METHODS))],
            'pay_to' => ['nullable', 'string', 'max:150'],
            ...\App\Support\PayTo::rules(),
            'basic_pay' => ['required', 'numeric', 'min:0'],
            'allowances' => ['nullable', 'array', 'max:10'],
            'allowances.*.name' => ['nullable', 'string', 'max:60'],
            'allowances.*.amount' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'id_number' => ['nullable', 'string', 'max:20'],
            'kra_pin' => ['nullable', 'string', 'max:20'],
        ], ['name.required' => 'Who is it?', 'basic_pay.required' => 'Enter their basic pay.']);
        $e = $this->payroll->saveEmployee($place, $request->user(), $data, $e);

        return $this->ok($this->presentEmployee($e), $id ? 'Saved.' : "{$e->name} added to the payroll.", $id ? 200 : 201);
    }

    /** POST /accounting/payroll/runs {month} - start a month. */
    public function start(Request $request): JsonResponse
    {
        $place = $this->placeFor($request, true);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $data = $request->validate(['month' => ['required', 'string', 'size:7']]);
        $run = $this->payroll->start($place, $request->user(), $data['month']);

        return $this->ok($this->presentRun($run, $request->user(), true), 'Payroll for '.$run->label().' started - check it, then send it for approval.', 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        [$run, , $deny] = $this->run($request, $id);

        return $deny ?? $this->ok($this->presentRun($run, $request->user(), true));
    }

    /** PUT /accounting/payroll/runs/{id}/payslips/{slip} */
    public function updateSlip(Request $request, int $id, int $slip): JsonResponse
    {
        [$run, , $deny] = $this->run($request, $id, true);
        if ($deny) {
            return $deny;
        }
        $p = Payslip::where('payroll_run_id', $run->id)->find($slip);
        if (! $p) {
            return $this->notFound('That payslip isn\'t in this run.');
        }
        $data = $request->validate([
            'basic' => ['nullable', 'numeric', 'min:0'],
            'allowances' => ['nullable', 'array', 'max:10'],
            'allowances.*.name' => ['nullable', 'string', 'max:60'],
            'allowances.*.amount' => ['nullable', 'numeric', 'min:0'],
            'other' => ['nullable', 'numeric', 'min:0'],
            'other_note' => ['nullable', 'string', 'max:150'],
        ]);
        $this->payroll->updateSlip($run, $p, $data);

        return $this->ok($this->presentRun($run->fresh(), $request->user(), true), 'Saved.');
    }

    public function addSlip(Request $request, int $id): JsonResponse
    {
        [$run, , $deny] = $this->run($request, $id, true);
        if ($deny) {
            return $deny;
        }
        $data = $request->validate(['employee_id' => ['required', 'integer']]);
        $this->payroll->addSlip($run, (int) $data['employee_id']);

        return $this->ok($this->presentRun($run->fresh(), $request->user(), true), 'Added.', 201);
    }

    public function removeSlip(Request $request, int $id, int $slip): JsonResponse
    {
        [$run, , $deny] = $this->run($request, $id, true);
        if ($deny) {
            return $deny;
        }
        $p = Payslip::where('payroll_run_id', $run->id)->find($slip);
        if (! $p) {
            return $this->notFound('That payslip isn\'t in this run.');
        }
        $this->payroll->removeSlip($run, $p);

        return $this->ok($this->presentRun($run->fresh(), $request->user(), true), 'Taken off this run.');
    }

    /** POST /accounting/payroll/runs/{id}/{recalculate|submit|cancel} */
    public function act(Request $request, int $id, string $act): JsonResponse
    {
        [$run, , $deny] = $this->run($request, $id, true);
        if ($deny) {
            return $deny;
        }
        $run = match ($act) {
            'recalculate' => $this->payroll->recalculate($run),
            'submit' => $this->payroll->submit($run, $request->user()),
            'cancel' => $this->payroll->cancel($run, $request->user()),
        };

        return $this->ok($this->presentRun($run, $request->user(), true), [
            'recalculate' => 'Read again from what each person is paid now.',
            'submit' => $run->status === 'posted' ? 'Approved and posted.' : 'Sent for approval'.(($who = app(\App\Approval\Services\Handover::class)->sentence($run)) ? " - {$who}." : '.'),
            'cancel' => 'Cancelled.',
        ][$act]);
    }

    /** POST /accounting/payroll/runs/{id}/{approve|reject|return} {comment} */
    public function decide(Request $request, int $id, string $decision): JsonResponse
    {
        [$run, , $deny] = $this->run($request, $id);
        if ($deny) {
            return $deny;
        }
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:500']]);
        $run = $this->payroll->decide($run, $request->user(), $decision, $data['comment'] ?? null);

        return $this->ok($this->presentRun($run, $request->user(), true), ['approve' => $run->status === 'posted' ? 'Approved - it is posted and ready to pay.' : 'Approved - it moves to the next stage.', 'reject' => 'Rejected - it is a draft again.', 'return' => 'Sent back for changes.'][$decision]);
    }

    /** POST /accounting/payroll/runs/{id}/pay {pay_from_account_id} - the staff's voucher, already authorised. */
    public function pay(Request $request, int $id): JsonResponse
    {
        [$run, $place, $deny] = $this->run($request, $id, true);
        if ($deny) {
            return $deny;
        }
        if (! AccountingAccess::can($request->user(), $place, 'prepare')) {
            return $this->forbidden('Your role can\'t make payments here.');
        }
        $data = $request->validate(['pay_from_account_id' => ['required', 'integer']]);
        $pv = $this->payroll->pay($run, $request->user(), (int) $data['pay_from_account_id']);

        return $this->ok(['voucher_id' => $pv->id, 'voucher_number' => $pv->number], "Voucher {$pv->number} is ready to pay - it is already authorised.", 201);
    }

    /** PUT /accounting/payroll/runs/{id}/references {refs: {payslip id: reference}} */
    public function references(Request $request, int $id): JsonResponse
    {
        [$run, , $deny] = $this->run($request, $id, true);
        if ($deny) {
            return $deny;
        }
        $data = $request->validate(['refs' => ['required', 'array'], 'refs.*' => ['nullable', 'string', 'max:60']]);
        $this->payroll->references($run, $data['refs']);

        return $this->ok($this->presentRun($run->fresh(), $request->user(), true), 'References saved.');
    }

    // ------------------------------------------------------------ presenting

    private function presentEmployee(Employee $e): array
    {
        return [
            'id' => $e->id, 'name' => $e->name, 'phone' => $e->phone, 'email' => $e->email, 'position' => $e->position,
            'start_date' => $e->start_date?->toDateString(), 'end_date' => $e->end_date?->toDateString(),
            'pay_method' => $e->pay_method, 'pay_method_label' => Employee::METHODS[$e->pay_method] ?? $e->pay_method, 'pay_to' => $e->pay_to, 'payee' => $e->payee,
            'basic_pay' => (float) $e->basic_pay, 'allowances' => $e->allowances ?? [], 'gross' => round((float) $e->basic_pay + array_sum(array_column($e->allowances ?? [], 'amount')), 2),
            'is_active' => $e->is_active,
            // Masked, always - the full numbers never leave the server.
            'id_number' => Employee::mask($e->id_number), 'kra_pin' => Employee::mask($e->kra_pin),
        ];
    }

    private function presentRun(PayrollRun $run, $user, bool $full = false): array
    {
        $place = Territory::find($run->territory_id);
        $manage = AccountingAccess::can($user, $place, 'payroll');
        $req = $this->engine->latest($run);
        $turn = $req ? $this->inbox->myTurn($user, $req) : null;
        $legacy = ! $req && $run->status === 'submitted' && AccountingAccess::can($user, $place, 'authorise') && (int) $run->prepared_by !== (int) $user->id;
        $draft = in_array($run->status, ['draft', 'returned'], true);
        $out = [
            'id' => $run->id, 'month' => $run->month, 'label' => $run->label(), 'status' => $run->status, 'status_label' => PayrollRun::STATUSES[$run->status],
            'gross' => (float) $run->gross, 'deductions' => (float) $run->deductions, 'net' => (float) $run->net,
            'people' => $run->payslips()->count(), 'decision_note' => $run->decision_note,
            'can' => [
                'edit' => $manage && $draft,
                'submit' => $manage && $draft,
                'cancel' => $manage && in_array($run->status, ['draft', 'returned', 'submitted'], true),
                'decide' => (bool) $turn || $legacy,
                'pay' => $manage && in_array($run->status, ['posted', 'paid'], true) && AccountingAccess::can($user, $place, 'prepare'),
            ],
        ];
        if ($full) {
            $payment = $this->payroll->openPayment($run);
            $run->loadMissing(['preparer', 'approver']);
            $out += [
                'prepared_by' => $run->preparer?->full_name,
                'approved_by' => $run->approver?->full_name,
                'approved_at' => $run->approved_at?->toIso8601String(),
                'journal_id' => $run->journal_id,
                'place' => $this->placeInfo($place),
                'payslips' => $run->payslips()->get()->map(fn ($p) => [
                    'id' => $p->id, 'employee_id' => $p->employee_id, 'name' => $p->name, 'position' => $p->position, 'pay_method' => $p->pay_method, 'pay_to' => $p->pay_to, 'payee' => $p->payee,
                    'basic' => (float) $p->basic, 'allowances' => $p->allowances ?? [], 'gross' => (float) $p->gross,
                    'other' => (float) $p->other, 'other_note' => $p->other_note, 'net' => (float) $p->net, 'reference' => $p->reference,
                ])->values(),
                'payment' => $payment?->voucher ? ['id' => $payment->voucher->id, 'number' => $payment->voucher->number, 'status' => $payment->voucher->status] : null,
                'approval' => $req ? $this->inbox->present($req, $user, true) : null,
            ];
        }

        return $out;
    }

    // ------------------------------------------------------------ access

    private function nextMonth(Territory $place): string
    {
        $last = PayrollRun::where('territory_id', $place->id)->where('status', '!=', 'cancelled')->max('month');

        return $last ? date('Y-m', strtotime("{$last}-01 +1 month")) : now()->format('Y-m');
    }

    private function placeFor(Request $request, bool $manage = false): Territory|JsonResponse
    {
        $id = $request->input('territory_id', $request->query('territory_id'));
        $place = PlaceAccess::place($request->user(), $id !== null && ctype_digit((string) $id) ? (int) $id : null);
        if (! $place || ! AccountingAccess::canSeePayroll($request->user(), $place)) {
            return $this->forbidden('This payroll isn\'t yours to see.');
        }
        if ($manage && ! AccountingAccess::can($request->user(), $place, 'payroll')) {
            return $this->forbidden('Only whoever runs payroll here changes it.');
        }

        return $place;
    }

    /** @return array{0: ?PayrollRun, 1: ?Territory, 2: ?JsonResponse} */
    private function run(Request $request, int $id, bool $manage = false): array
    {
        $run = PayrollRun::find($id);
        $place = $run ? Territory::find($run->territory_id) : null;
        $user = $request->user();
        $sees = $run && $place && (AccountingAccess::canSeePayroll($user, $place) || ($this->engine->latest($run) && $this->inbox->canView($user, $this->engine->latest($run))));
        if (! $sees) {
            return [null, null, $this->notFound('That payroll isn\'t yours to see.')];
        }
        if ($manage && ! AccountingAccess::can($user, $place, 'payroll')) {
            return [null, null, $this->forbidden('Only whoever runs payroll here changes it.')];
        }

        return [$run, $place, null];
    }

    private function can(Request $request, Territory $place): array
    {
        $a = AccountingAccess::abilities($request->user(), $place);

        return ['manage' => $a['payroll'], 'pay' => $a['prepare'] && $a['payroll'], 'own' => $a['own']];
    }
}
