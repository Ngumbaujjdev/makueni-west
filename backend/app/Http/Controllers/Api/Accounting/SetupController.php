<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Models\AccountingAccount;
use App\Models\AccountingFund;
use App\Models\AccountingPeriod;
use App\Models\AccountingPlaceAccount;
use App\Models\Employee;
use App\Models\JournalLine;
use App\Models\Supplier;
use App\Support\AccountingAccess;
use App\Support\PlaceRoles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Setting up the books (docs/specs/accounting-spec.md, setup): what a place
 * has done and what is left - its own bank and M-Pesa accounts, opening
 * balances, the petty cash float, its own funds, suppliers, the staff it
 * pays, the first month closed - and who holds each job here. Nothing about
 * who records is hard-coded: a job nobody holds says so, and the diocese
 * admin gives it in Roles & permissions.
 */
class SetupController extends AccountingBase
{
    /** The jobs a place needs someone for: ability => [what it is, levels it applies to (null = all)]. */
    private const JOBS = [
        'collect' => ['Record Sunday collections', ['church']],
        'receipt' => ['Write receipts', null],
        'prepare' => ['Prepare payments', null],
        'authorise' => ['Authorise payments', null],
        'pay' => ['Pay vouchers', null],
        'reconcile' => ['Reconcile the bank and M-Pesa', null],
        'close' => ['Close the month', null],
    ];

    /** GET /accounting/setup */
    public function index(Request $request): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $level = $place->territory_type->value;
        $own = AccountingAccount::where('territory_id', $place->id);
        $cash = (clone $own)->whereNotNull('cash_kind')->where('is_active', true)->count();
        $opening = JournalLine::where('journal_lines.territory_id', $place->id)
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')->where('journals.doc_type', 'journal')
            ->where(fn ($q) => $q->whereNull('journals.source_type')->orWhere('journals.source_type', '!=', 'accounting_year'))
            ->whereIn('journal_lines.account_id', AccountingAccount::where('type', 'fund')->select('id'))->exists();
        $petty = AccountingPlaceAccount::where('territory_id', $place->id)->whereNotNull('imprest_float')->exists();
        $funds = AccountingFund::where('territory_id', $place->id)->count();
        $subs = (clone $own)->whereNull('cash_kind')->whereIn('type', ['income', 'expense'])->count();
        $suppliers = Supplier::where('territory_id', $place->id)->where('is_active', true)->count();
        $staff = Employee::where('territory_id', $place->id)->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', today()))->count();
        $closed = AccountingPeriod::where('territory_id', $place->id)->where('status', 'closed')->orderByDesc('year')->orderByDesc('month')->first();

        $steps = [
            $this->step('accounts', 'Add your bank and M-Pesa accounts', $cash > 0, $cash ? "{$cash} ".($cash === 1 ? 'account' : 'accounts') : 'Only cash at hand so far', 'accounts', false),
            $this->step('opening', 'Post the opening balances', $opening, $opening ? 'Posted' : 'What each account held when you started', 'journals', false),
            $this->step('petty', 'Set the petty cash float', $petty, $petty ? 'Set' : 'Only if you keep petty cash', 'accounts', true),
            $this->step('funds', 'Add your own funds', $funds > 0, $funds ? "{$funds} of your own" : 'Only for a project of your own, e.g. a van harambee', 'funds', true),
            $this->step('lines', 'Add finer income or spending lines', $subs > 0, $subs ? "{$subs} of your own" : 'Only if you want e.g. "Youth offering" apart', 'chart', true),
            $this->step('suppliers', 'Add your suppliers', $suppliers > 0, $suppliers ? "{$suppliers} ".($suppliers === 1 ? 'supplier' : 'suppliers') : 'Those you buy from regularly', 'procurement', true),
            $this->step('staff', 'Add the people you pay', $staff > 0, $staff ? "{$staff} on the staff" : 'In Staff - payroll pays from there', 'staff', true),
            $this->step('close', 'Close your first month', (bool) $closed, $closed ? 'Last closed: '.date('F Y', mktime(0, 0, 0, $closed->month, 1, $closed->year)) : 'Once a month is counted and reconciled', 'close', false),
        ];
        $jobs = [];
        foreach (self::JOBS as $ability => [$label, $levels]) {
            if ($levels && ! in_array($level, $levels, true)) {
                continue;
            }
            $suffix = AccountingAccess::ABILITIES[$ability];
            $people = PlaceRoles::withPermission($place, $suffix);
            $jobs[] = ['ability' => $ability, 'label' => $label, 'permission' => $suffix, 'holders' => $people->map(fn ($u) => $u->full_name)->values(), 'held' => $people->isNotEmpty()];
        }
        $needed = array_filter($steps, fn ($s) => ! $s['optional']);

        return $this->ok([
            'place' => $this->placeInfo($place),
            'can' => AccountingAccess::abilities($request->user(), $place),
            'steps' => $steps,
            'jobs' => $jobs,
            'done' => count(array_filter($needed, fn ($s) => $s['done'])),
            'of' => count($needed),
            'unheld' => count(array_filter($jobs, fn ($j) => ! $j['held'])),
        ]);
    }

    private function step(string $key, string $title, bool $done, string $detail, string $page, bool $optional): array
    {
        return compact('key', 'title', 'done', 'detail', 'page', 'optional');
    }
}
