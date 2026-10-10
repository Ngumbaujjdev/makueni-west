<?php

namespace Database\Seeders;

use App\Approval\Services\ApprovalService;
use App\Models\ApprovalAssignment;
use App\Models\PaymentVoucher;
use App\Models\PayrollRun;
use App\Models\Requisition;
use App\Models\StaffAdvance;
use App\Models\Territory;
use App\Models\User;
use App\Models\UserTerritoryAssignment;
use App\Services\Accounting\CashCounts;
use App\Services\Accounting\Chart;
use App\Services\Accounting\Collections;
use App\Services\Accounting\Documents;
use App\Services\Accounting\Ledger;
use App\Services\Accounting\PaymentVouchers;
use App\Services\Accounting\Payroll;
use App\Services\Accounting\Periods;
use App\Services\Accounting\Procurement;
use App\Services\Accounting\Reconciliations;
use App\Services\Accounting\Remittances;
use App\Services\Accounting\Requisitions;
use App\Services\Accounting\StaffAdvances;
use App\Services\Budgets\BudgetBook;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use RuntimeException;

/**
 * Demo books for one church (docs/specs/accounting-spec.md, "Demo data"):
 * from January 2025 to today, a real church's money - opening balances, every
 * Sunday's collection counted and confirmed and banked, monthly bills paid by
 * voucher, a repair and a youth camp advance asked for and approved, a sound
 * mixer bought on an order with three quotes, three staff paid monthly, the
 * diocese's share sent and confirmed, and each month counted, reconciled and
 * closed - with a few things left waiting so Approvals isn't empty.
 *
 * Everything goes through the Accounting services, so every journal balances.
 * Dated from 1 January 2025 to today. Budgets are left exactly as they are:
 * while it runs no budget counts as "in use", so nothing is copied into the
 * budgets of those months. No message is sent: jobs, mail and notifications
 * are faked.
 * What it made is kept as id ranges in the church's metadata
 * (accounting_demo) for AccountingDemoRemoveSeeder.
 *
 * php artisan db:seed --class=AccountingDemoSeeder
 */
class AccountingDemoSeeder extends Seeder
{
    public const KEY = 'accounting_demo';

    /** Where the leaders' real start dates wait while the demo writes. */
    public const ROLES_KEY = 'accounting_demo_roles';

    public const START = '2025-01-01';

    /** The months closed in the demo: up to and including this one. */
    public const CLOSE_TO = '2026-08';

    /** Rows the demo makes, by table: [the column naming its place(s)]. Children go with their parents. */
    public const TABLES = [
        'approval_requests' => ['territory_id'],
        'bank_reconciliations' => ['territory_id'],
        'cash_counts' => ['territory_id'],
        'staff_advances' => ['territory_id'],
        'supplier_invoices' => ['territory_id'],
        'goods_received' => ['territory_id'],
        'purchase_orders' => ['territory_id'],
        'requisitions' => ['territory_id'],
        'suppliers' => ['territory_id'],
        'payroll_runs' => ['territory_id'],
        'employees' => ['territory_id'],
        'payment_vouchers' => ['territory_id'],
        'collections' => ['territory_id'],
        'remittances' => ['from_territory_id', 'to_territory_id'],
        'journals' => ['territory_id'],
        'accounting_periods' => ['territory_id'],
        'accounting_sequences' => ['territory_id'],
        'accounting_accounts' => ['territory_id'],
        'equipment' => ['territory_id'],
    ];

    private Territory $church;

    private Territory $diocese;

    private User $treasurer;

    private User $checker;

    private User $dioceseUser;

    private ?User $authoriser = null;

    /** @var array<string, int> */
    private array $acc = [];

    private CarbonImmutable $today;

    /** The first day of the demo books. */
    private string $from = self::START;

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('Demo data is not for production.');

            return;
        }
        $church = Territory::where('code', 'CCI-MWD-SHR-001')->first();
        if (! $church) {
            $this->command?->error('CCI SULTAN HAMUD (CCI-MWD-SHR-001) not found.');

            return;
        }
        $at = fn (Territory $p, string $role) => UserTerritoryAssignment::with('user')->where('territory_id', $p->id)->where('is_active', true)
            ->whereHas('role', fn ($q) => $q->where('name', $role))->orderBy('id')->get()->pluck('user')->filter()->values();
        $diocese = $this->dioceseOf($church);
        $assistants = $at($church, 'Associate Pastor');
        $senior = $at($church, 'Senior Pastor')->first();
        $bishop = $diocese ? $at($diocese, 'Bishop')->first() : null;
        if ($assistants->count() < 2 || ! $senior || ! $bishop) {
            $this->command?->error('The church needs a Senior Pastor and two Associate Pastors, and the diocese a Bishop.');

            return;
        }
        $made = $this->build($church, $diocese, $assistants[0], $assistants[1], $bishop, $senior);
        $this->command?->info('💰 ACCOUNTING DEMO - '.$church->name.' (peak memory '.round(memory_get_peak_usage(true) / 1048576).' MB)');
        foreach ($made as $what => $n) {
            $this->command?->info("   ✅ {$what}: {$n}");
        }
        $this->command?->info('   Remove it with: php artisan db:seed --class=AccountingDemoRemoveSeeder');
    }

    /**
     * The whole demo for a church. The treasurer writes things up; the checker
     * is the second person (confirms collections, signs off reconciliations);
     * approvals go to whoever the approval rules name (the authoriser when
     * there is no rule). Returns what was made.
     *
     * @return array<string, int>
     */
    public function build(Territory $church, Territory $diocese, User $treasurer, User $checker, User $dioceseUser, ?User $authoriser = null, ?string $from = null): array
    {
        $this->from = $from ?? self::START;
        self::restoreRoles($church);
        AccountingDemoRemoveSeeder::removeDemo($church);
        ini_set('memory_limit', '2G');
        DB::disableQueryLog();
        [$this->church, $this->diocese, $this->treasurer, $this->checker, $this->dioceseUser, $this->authoriser] = [$church, $diocese, $treasurer, $checker, $dioceseUser, $authoriser];
        $this->today = CarbonImmutable::now('Africa/Nairobi')->startOfDay();
        mt_srand(2026);

        $before = $this->maxIds();
        $realNow = Carbon::getTestNow();
        $audit = config('audit.enabled');
        config(['audit.enabled' => false]);
        Bus::fake();
        Queue::fake();
        Mail::fake();
        Notification::fake();
        // Leave every budget as it is: while the demo writes, no budget is "in use".
        $entries = (int) DB::table('budget_entries')->max('id');
        app()->instance(BudgetBook::class, new class
        {
            public function budgetInUseOn(...$args): mixed
            {
                return null;
            }
        });
        // The leaders hold their roles from when they were added to the system; for the
        // approval rules to find them on older dates, their start is moved back while
        // the demo writes, and put back exactly afterwards.
        $places = [$church->id, $diocese->id];
        for ($p = $church; $p && $p->parent_territory_id; $p = Territory::find($p->parent_territory_id)) {
            $places[] = (int) $p->parent_territory_id;
        }
        $started = DB::table('user_territory_assignments')->whereIn('territory_id', array_unique($places))->where('effective_from', '>', $this->from)->pluck('effective_from', 'id')->all();
        // Kept in the church's metadata first, so even a crash can be put right (restoreRoles).
        self::remember($church, self::ROLES_KEY, $started);
        DB::table('user_territory_assignments')->whereIn('id', array_keys($started))->update(['effective_from' => CarbonImmutable::parse($this->from)->subDay()->toDateTimeString()]);
        try {
            $this->openBooks();
            $this->months();
            if ((int) DB::table('budget_entries')->max('id') !== $entries) {
                throw new RuntimeException('The demo reached Budgets - stopped.');
            }
        } finally {
            self::restoreRoles($church);
            app()->forgetInstance(BudgetBook::class);
            Carbon::setTestNow($realNow);
            CarbonImmutable::setTestNow($realNow);
            config(['audit.enabled' => $audit]);
            // Record what was made even if something failed part way, so it can be removed.
            $after = $this->maxIds();
            $ranges = [];
            foreach ($before as $t => $from) {
                if ($after[$t] > $from) {
                    $ranges[$t] = [$from + 1, $after[$t]];
                }
            }
            self::remember($church, self::KEY, ['places' => [$church->id, $diocese->id], 'ranges' => $ranges, 'made_at' => now()->toDateTimeString()]);
        }

        return [
            'Sunday collections' => DB::table('collections')->where('territory_id', $church->id)->count(),
            'payment vouchers' => PaymentVoucher::where('territory_id', $church->id)->count(),
            'requisitions' => Requisition::where('territory_id', $church->id)->count(),
            'payroll runs' => PayrollRun::where('territory_id', $church->id)->count(),
            'remittances' => DB::table('remittances')->where('from_territory_id', $church->id)->count(),
            'journals' => DB::table('journals')->whereIn('territory_id', [$church->id, $diocese->id])->count(),
            'months closed' => DB::table('accounting_periods')->where('territory_id', $church->id)->where('status', 'closed')->count(),
        ];
    }

    // ------------------------------------------------------------------ the books

    /** Accounts and the opening balances on 1 April. */
    private function openBooks(): void
    {
        $this->at($this->from.' 08:00');
        $chart = app(Chart::class);
        $chart->ensureStandard();
        $this->acc = [
            'cash' => $chart->account('cash_at_hand')->id,
            'bank' => $chart->addPlaceAccount($this->church, 'bank', ['name' => 'Equity Bank - CCI Sultan Hamud', 'bank_name' => 'Equity Bank', 'branch' => 'Emali', 'account_number' => '0790 2981 4417'], $this->treasurer->id)->id,
            'mpesa' => $chart->addPlaceAccount($this->church, 'mpesa', ['name' => 'M-Pesa till 552 718', 'mpesa_number' => '552718'], $this->treasurer->id)->id,
            'dioceseBank' => $chart->addPlaceAccount($this->diocese, 'bank', ['name' => 'KCB - Makueni West Diocese', 'bank_name' => 'KCB', 'branch' => 'Wote', 'account_number' => '1258 4400 91'], $this->dioceseUser->id)->id,
        ];
        foreach ([4000, 4010, 4020, 4030, 4040, 4200, 5000, 5100, 5110, 5150, 5300, 5310, 5320, 5400, 5410, 5420, 5500, 5530] as $code) {
            $this->acc[(string) $code] = $this->code((string) $code);
        }
        $general = $chart->generalFund()->id;
        app(Documents::class)->journalVoucher($this->church, $this->treasurer, [
            'date' => $this->from,
            'narration' => 'Opening balances - cash counted, the bank statement and the till on '.CarbonImmutable::parse($this->from)->subDay()->format('j F Y').' · demo',
            'lines' => [
                ['account_id' => $this->acc['cash'], 'debit' => 18500],
                ['account_id' => $this->acc['bank'], 'debit' => 245000],
                ['account_id' => $this->acc['mpesa'], 'debit' => 32000],
                ['account_id' => $this->code('3000'), 'credit' => 295500, 'fund_id' => $general],
            ],
        ]);
        foreach ([
            ['Mwikali Nduku', 'Church secretary', 18000, [['name' => 'Airtime', 'amount' => 1000]], '+254700000901'],
            ['Kioko Mutinda', 'Caretaker', 12000, [], '+254700000902'],
            ['Musyoka Ndambuki', 'Night guard', 10000, [['name' => 'Night duty', 'amount' => 1500]], '+254700000903'],
        ] as [$name, $position, $basic, $allowances, $phone]) {
            app(Payroll::class)->saveEmployee($this->church, $this->treasurer, [
                'name' => $name, 'position' => $position, 'phone' => $phone, 'start_date' => $this->from,
                'pay_method' => 'mpesa', 'pay_to' => $phone, 'basic_pay' => $basic, 'allowances' => $allowances,
            ]);
        }
        foreach ([['Emali Sound & Electronics', '+254700000911'], ['Machakos Music Centre', '+254700000912'], ['Nairobi Pro Audio', '+254700000913']] as [$name, $phone]) {
            app(Procurement::class)->saveSupplier($this->church, $this->treasurer, ['name' => $name, 'phone' => $phone, 'notes' => 'DEMO_ACC']);
        }
    }

    /** Month by month, from April to now. */
    private function months(): void
    {
        for ($m = CarbonImmutable::parse($this->from); $m->lte($this->today); $m = $m->addMonth()) {
            $end = $m->endOfMonth()->startOfDay();
            $last = $end->lt($this->today) ? $end : $this->today;
            $tithes = 0;
            for ($d = $m; $d->lte($last); $d = $d->addDay()) {
                if ($d->isSunday()) {
                    $tithes += $this->sunday($d);
                }
                $this->day($d);
            }
            if ($end->lt($this->today)) {
                $this->monthEnd($end);
                $this->afterMonth($end, $tithes);
            } else {
                $this->waitingNow();
            }
        }
    }

    /** A Sunday's collection: counted, confirmed, the cash banked on Monday. Returns its tithes. */
    private function sunday(CarbonImmutable $d): float
    {
        $this->at($d->format('Y-m-d').' 13:00');
        $kinds = [
            ['Tithe', '4000', 'GEN', mt_rand(140, 260) * 100, 0.45],
            ['Offering', '4010', 'GEN', mt_rand(70, 140) * 100, 0.3],
            ['Building fund', '4020', 'BLD', mt_rand(15, 60) * 100, 0.4],
            ['KYS', '4020', 'KYS', mt_rand(0, 3) * 500, 0.2],
        ];
        $lines = [];
        $tithes = 0;
        foreach ($kinds as [$label, $code, $fund, $total, $byPhone]) {
            if ($total <= 0) {
                continue;
            }
            $mpesa = round($total * $byPhone / 50) * 50;
            $lines[] = ['label' => $label, 'account_id' => $this->acc[$code], 'fund_id' => $this->fund($fund), 'cash_amount' => $total - $mpesa, 'mpesa_amount' => $mpesa];
            $tithes += $code === '4000' ? $total : 0;
        }
        $c = app(Collections::class)->record($this->church, $this->treasurer, [
            'date' => $d->toDateString(),
            'title' => 'Sunday service',
            'cash_account_id' => $this->acc['cash'],
            'mpesa_account_id' => $this->acc['mpesa'],
            'notes' => 'DEMO_ACC',
            'lines' => $lines,
        ]);
        app(Collections::class)->confirm($c, $this->checker);
        $monday = $d->addDay();
        if ($monday->lte($this->today)) {
            $this->at($monday->format('Y-m-d').' 10:30');
            // A little is kept back each week for the small bills paid in cash.
            $c = $c->fresh();
            $keep = min(4000, (float) $c->cash_total);
            if ((float) $c->cash_total - $keep > 0) {
                app(Collections::class)->bank($c, $this->treasurer, ['date' => $monday->toDateString(), 'to_account_id' => $this->acc['bank'], 'amount' => (float) $c->cash_total - $keep, 'reference' => 'DEMO-DEP-'.$monday->format('ymd')]);
            }
        }

        return $tithes;
    }

    /** What happens on particular days of the month. */
    private function day(CarbonImmutable $d): void
    {
        $day = (int) $d->format('j');
        if ($day === 5) {
            $this->voucher($d, 'Kenya Power', 'Electricity for the church and hall - '.$d->format('F'), [['5400', mt_rand(42, 58) * 100]], 'bank');
            $this->voucher($d, 'Emali Water Company', 'Water - '.$d->format('F'), [['5410', mt_rand(12, 18) * 100]], 'mpesa');
            $this->voucher($d, 'Safaricom Home Fibre', 'Internet - '.$d->format('F'), [['5420', 2999]], 'mpesa');
        }
        if ($day === 15) {
            $this->receipt($d, 'Mutiso family', 'Hall hire for a wedding reception', [['4200', mt_rand(3, 6) * 1000]], 'mpesa');
        }
        if ($day === 20) {
            $this->voucher($d, 'Emali Supermarket', 'Cleaning supplies - '.$d->format('F'), [['5320', mt_rand(20, 32) * 100]], 'cash');
            $this->voucher($d, 'Pastor Benson Manoo', 'Transport to the region meeting and hospital visits', [['5530', 6000]], 'cash');
        }
        match ($d->toDateString()) {
            '2025-03-11' => $this->voucher($d, 'Kibwezi Printers', 'New hymn books for the choir', [['5150', 9600]], 'bank'),
            '2025-04-15' => $this->voucher($d, 'Kibwezi Printers', 'Easter programmes and banners', [['5500', 7200]], 'bank'),
            '2025-06-18' => $this->voucher($d, 'Mutua Fundi', 'Repainting the church doors and windows', [['5300', 14500]], 'cash'),
            '2025-09-14' => $this->receipt($d, 'Building harambee', 'Building fund harambee - the first pledges', [['4040', 120000, 'BLD']], 'bank'),
            '2025-11-22' => $this->voucher($d, 'Emali Hardware', 'Iron sheets for the new kitchen roof', [['5300', 38500]], 'bank'),
            '2025-12-18' => $this->voucher($d, 'Kibwezi Printers', 'Christmas carols programmes and decorations', [['5500', 8400]], 'bank'),
            '2026-02-07' => $this->receipt($d, 'A well-wisher', 'Gift towards the Valentine couples\' dinner', [['4030', 10000]], 'mpesa'),
            '2026-04-24' => $this->voucher($d, 'Kibwezi Printers', 'Easter programmes and banners', [['5500', 7800]], 'bank'),
            '2026-05-12' => $this->repairs($d),
            '2026-06-03' => $this->soundMixer($d),
            '2026-06-21' => $this->receipt($d, 'Building harambee', 'Building fund harambee - pledges paid in', [['4040', 85000, 'BLD']], 'bank'),
            '2026-07-08' => $this->youthCamp($d),
            '2026-08-16' => $this->receipt($d, 'A well-wisher', 'Gift towards the children\'s ministry', [['4030', 15000]], 'bank'),
            '2026-09-04' => $this->voucher($d, 'Holy Communion supplies', 'Communion wine and bread for the quarter', [['5100', 4500]], 'cash'),
            default => null,
        };
    }

    /** The last day of a finished month: payroll, then the till swept to the bank. */
    private function monthEnd(CarbonImmutable $end): void
    {
        $this->at($end->format('Y-m-d').' 15:00');
        $payroll = app(Payroll::class);
        $run = $payroll->start($this->church, $this->treasurer, $end->format('Y-m'));
        $run = $payroll->submit($run, $this->treasurer);
        $this->approve($run, fn ($u) => $payroll->decide($run, $u, 'approve', null));
        $pv = $payroll->pay($run->fresh(), $this->treasurer, $this->acc['bank']);
        app(PaymentVouchers::class)->pay($pv, $this->treasurer, ['paid_on' => $end->toDateString(), 'reference' => 'DEMO-PAY-'.$end->format('Ym')]);

        $this->at($end->format('Y-m-d').' 17:30');
        $cash = app(Ledger::class)->balance($this->church, $this->model($this->acc['cash']), $end->toDateString());
        if ($cash > 25000) {
            app(Documents::class)->transfer($this->church, $this->treasurer, [
                'date' => $end->toDateString(), 'from_account_id' => $this->acc['cash'], 'to_account_id' => $this->acc['bank'],
                'amount' => floor(($cash - 15000) / 100) * 100, 'reference' => 'DEMO-CASH-'.$end->format('Ym'), 'narration' => 'Extra cash at hand banked · demo',
            ]);
        }
        $till = app(Ledger::class)->balance($this->church, $this->model($this->acc['mpesa']), $end->toDateString());
        if ($till > 8000) {
            app(Documents::class)->transfer($this->church, $this->treasurer, [
                'date' => $end->toDateString(), 'from_account_id' => $this->acc['mpesa'], 'to_account_id' => $this->acc['bank'],
                'amount' => floor(($till - 5000) / 100) * 100, 'reference' => 'DEMO-SWEEP-'.$end->format('Ym'), 'narration' => 'M-Pesa till swept to the bank · demo',
            ]);
        }
    }

    /** The first days of the next month: count, reconcile, close; the diocese's share sent and confirmed. */
    private function afterMonth(CarbonImmutable $end, float $tithes): void
    {
        $next = $end->addDay();
        $this->at($next->format('Y-m-d').' 09:00');
        $date = $end->toDateString();
        $ledger = app(Ledger::class);
        app(CashCounts::class)->count($this->church, $this->treasurer, [
            'account_id' => $this->acc['cash'], 'counted_on' => $date,
            'counted_total' => $ledger->balance($this->church, $this->model($this->acc['cash']), $date),
        ]);
        $recs = app(Reconciliations::class);
        foreach (['bank', 'mpesa'] as $kind) {
            $account = $this->model($this->acc[$kind]);
            $rec = $recs->start($this->church, $this->treasurer, ['account_id' => $account->id, 'statement_date' => $date, 'statement_balance' => $ledger->balance($this->church, $account, $date)]);
            $recs->tick($rec, $recs->bookLines($rec)->pluck('id')->all(), true);
            $rec = $recs->submit($rec->fresh(), $this->treasurer);
            $recs->approve($rec, $this->checker);
        }
        if ($end->format('Y-m') <= self::CLOSE_TO) {
            app(Periods::class)->close($this->church, $this->treasurer, (int) $end->format('Y'), (int) $end->format('n'));
        }

        // The diocese's share of the month's tithes.
        $remittances = app(Remittances::class);
        $rule = $remittances->rulesOwed($this->church)->first();
        if (! $rule) {
            return;
        }
        $owed = collect(collect($remittances->owing($this->church, (int) $end->format('Y')))->firstWhere('id', $rule->id)['months'] ?? [])->firstWhere('month', $end->format('Y-m'));
        $amount = round((float) ($owed['owed'] ?? 0), 2);
        if ($amount <= 0) {
            return;
        }
        $this->at($next->addDay()->format('Y-m-d').' 10:00');
        $rem = $remittances->sendShare($this->church, $this->treasurer, ['budget_deduction_id' => $rule->id, 'pay_from_account_id' => $this->acc['bank'], 'lines' => [['month' => $end->format('Y-m'), 'amount' => $amount]]]);
        $pv = PaymentVoucher::findOrFail($rem->payment_voucher_id);
        $this->approve($pv, fn ($u) => app(PaymentVouchers::class)->authorise($pv, $u));
        app(PaymentVouchers::class)->pay($pv->fresh(), $this->treasurer, ['paid_on' => $next->addDay()->toDateString(), 'reference' => 'DEMO-RTGS-'.$end->format('Ym')]);
        // The diocese confirms it a few days later - all but the last month, still on its way.
        $received = $next->addDays(4);
        if ($received->lte($this->today) && $end->addMonthNoOverflow()->endOfMonth()->lt($this->today)) {
            $this->at($received->format('Y-m-d').' 11:00');
            $remittances->confirm($rem->fresh(), $this->dioceseUser, ['into_account_id' => $this->acc['dioceseBank'], 'received_on' => $received->toDateString()]);
        }
    }

    /** Things left waiting now: a voucher, a requisition and this month's payroll. */
    private function waitingNow(): void
    {
        $this->at($this->today->format('Y-m-d').' 09:30');
        app(PaymentVouchers::class)->prepare($this->church, $this->treasurer, [
            'date' => $this->today->toDateString(), 'payee_name' => 'Makueni Pest Control', 'pay_from_account_id' => $this->acc['bank'],
            'narration' => 'Fumigating the hall and the vestry · demo', 'lines' => [['account_id' => $this->acc['5320'], 'amount' => 7500]],
        ]);
        app(Requisitions::class)->create($this->church, $this->treasurer, [
            'kind' => 'payment', 'purpose' => 'Guitar strings and two microphone stands for the praise team · demo', 'amount' => 12000,
            'account_id' => $this->acc['5150'], 'payee_name' => 'Emali Sound & Electronics',
        ]);
        $payroll = app(Payroll::class);
        $payroll->submit($payroll->start($this->church, $this->treasurer, $this->today->format('Y-m')), $this->treasurer);
    }

    // ------------------------------------------------------------------ the stories

    /** A broken window and gutter: asked for, approved, paid. */
    private function repairs(CarbonImmutable $d): void
    {
        $r = app(Requisitions::class)->create($this->church, $this->treasurer, [
            'kind' => 'payment', 'purpose' => 'Replace the broken vestry window and fix the gutter · demo', 'amount' => 18500,
            'account_id' => $this->acc['5300'], 'payee_name' => 'Mutua Fundi',
        ]);
        $this->approve($r, fn ($u) => app(Requisitions::class)->decide($r, $u, 'approve', null));
        $this->at($d->addDays(2)->format('Y-m-d').' 10:00');
        $pv = app(Requisitions::class)->makePayment($r->fresh(), $this->treasurer, $this->acc['cash']);
        app(PaymentVouchers::class)->pay($pv, $this->treasurer, ['paid_on' => $d->addDays(2)->toDateString(), 'reference' => 'DEMO-CASH-REPAIR']);
        $this->at($d->format('Y-m-d').' 23:00');
    }

    /** The youth camp: an advance, paid, then accounted for with the change back. */
    private function youthCamp(CarbonImmutable $d): void
    {
        $r = app(Requisitions::class)->create($this->church, $this->treasurer, [
            'kind' => 'advance', 'purpose' => 'Youth camp at Kibwezi - food, transport and the venue · demo', 'amount' => 25000,
        ]);
        $this->approve($r, fn ($u) => app(Requisitions::class)->decide($r, $u, 'approve', null));
        $this->at($d->addDay()->format('Y-m-d').' 10:00');
        $pv = app(Requisitions::class)->makePayment($r->fresh(), $this->treasurer, $this->acc['bank']);
        app(PaymentVouchers::class)->pay($pv, $this->treasurer, ['paid_on' => $d->addDay()->toDateString(), 'reference' => 'DEMO-ADV-CAMP']);
        $advance = StaffAdvance::where('requisition_id', $r->id)->firstOrFail();
        $back = $d->addDays(14);
        $this->at($back->format('Y-m-d').' 16:00');
        app(StaffAdvances::class)->retire($advance, $this->treasurer, [
            'date' => $back->toDateString(),
            'lines' => [['account_id' => $this->acc['5110'], 'amount' => 21750, 'memo' => 'Food, transport and the venue - receipts kept']],
            'returned' => 3250, 'return_account_id' => $this->acc['cash'],
        ]);
        $this->at($d->format('Y-m-d').' 23:00');
    }

    /** The sound mixer: three quotes, the order, delivered (into Equipment), billed, paid. */
    private function soundMixer(CarbonImmutable $d): void
    {
        $proc = app(Procurement::class);
        $r = app(Requisitions::class)->create($this->church, $this->treasurer, [
            'kind' => 'purchase', 'purpose' => 'A 16-channel sound mixer for the main service · demo', 'amount' => 68000, 'account_id' => $this->acc['5310'],
        ]);
        $suppliers = \App\Models\Supplier::where('territory_id', $this->church->id)->where('notes', 'DEMO_ACC')->orderBy('id')->get();
        $quotes = [];
        foreach ([68000, 72500, 75000] as $i => $amount) {
            $quotes[] = $proc->addQuote($r, $this->treasurer, ['supplier_id' => $suppliers[$i]->id, 'amount' => $amount, 'notes' => 'Yamaha MG16XU']);
        }
        $proc->chooseQuote($r, $quotes[0], null);
        $this->approve($r, fn ($u) => app(Requisitions::class)->decide($r, $u, 'approve', null));

        $this->at($d->addDays(2)->format('Y-m-d').' 11:00');
        $po = $proc->raiseOrder($r->fresh(), $this->treasurer, ['date' => $d->addDays(2)->toDateString(), 'notes' => 'Deliver to the church office · demo',
            'lines' => [['description' => 'Yamaha MG16XU sound mixer', 'quantity' => 1, 'unit_price' => 68000, 'is_asset' => true]]]);
        $this->at($d->addDays(9)->format('Y-m-d').' 14:00');
        $line = $po->lines->first();
        $proc->receive($po, $this->treasurer, ['date' => $d->addDays(9)->toDateString(), 'lines' => [['line_id' => $line->id, 'quantity' => 1]], 'notes' => 'Delivered and tested · demo']);
        $bill = $proc->bill($po->fresh(), $this->treasurer, ['supplier_ref' => 'ESE-4471', 'date' => $d->addDays(9)->toDateString(), 'due_on' => $d->addDays(24)->toDateString(),
            'lines' => [['line_id' => $line->id, 'quantity' => 1, 'unit_price' => 68000]]]);
        $this->at($d->addDays(17)->format('Y-m-d').' 10:00');
        $pv = $proc->payBill($bill, $this->treasurer, $this->acc['bank']);
        app(PaymentVouchers::class)->pay($pv, $this->treasurer, ['paid_on' => $d->addDays(17)->toDateString(), 'reference' => 'DEMO-CHQ-001244']);
        $this->at($d->format('Y-m-d').' 23:00');
    }

    // ------------------------------------------------------------------ helpers

    /** A bill paid by voucher: prepared, approved, paid the same day. */
    private function voucher(CarbonImmutable $d, string $payee, string $what, array $lines, string $from): void
    {
        $this->at($d->format('Y-m-d').' 10:00');
        $pv = app(PaymentVouchers::class)->prepare($this->church, $this->treasurer, [
            'date' => $d->toDateString(), 'payee_name' => $payee, 'pay_from_account_id' => $this->acc[$from], 'narration' => "{$what} · demo",
            'lines' => array_map(fn ($l) => ['account_id' => $this->acc[$l[0]], 'amount' => $l[1], 'description' => $what], $lines),
        ]);
        $this->approve($pv, fn ($u) => app(PaymentVouchers::class)->authorise($pv, $u));
        $this->at($d->format('Y-m-d').' 15:00');
        app(PaymentVouchers::class)->pay($pv->fresh(), $this->treasurer, ['paid_on' => $d->toDateString(), 'reference' => 'DEMO-'.strtoupper($from).'-'.$pv->id]);
    }

    private function receipt(CarbonImmutable $d, string $from, string $what, array $lines, string $into): void
    {
        $this->at($d->format('Y-m-d').' 12:00');
        app(Documents::class)->receipt($this->church, $this->treasurer, [
            'date' => $d->toDateString(), 'account_id' => $this->acc[$into], 'party_name' => $from, 'reference' => 'DEMO-RCT-'.$d->format('ymd'), 'narration' => "{$what} · demo",
            'lines' => array_map(fn ($l) => ['account_id' => $this->acc[$l[0]], 'amount' => $l[1], 'fund_id' => isset($l[2]) ? $this->fund($l[2]) : null], $lines),
        ]);
    }

    /**
     * Approve a document: each step by whoever the approval rules have
     * waiting on it; with no rule for it, the authoriser through $fallback.
     */
    private function approve(Model $doc, callable $fallback): void
    {
        $engine = app(ApprovalService::class);
        for ($i = 0; $i < 6 && ($request = $engine->current($doc->fresh())); $i++) {
            $turn = ApprovalAssignment::where('request_id', $request->id)->where('status', 'pending')->whereNull('superseded_at')
                ->whereHas('stage', fn ($q) => $q->where('status', 'active'))->orderBy('id')->first();
            if (! $turn) {
                throw new RuntimeException('No one is waiting to approve '.class_basename($doc)." {$doc->id}.");
            }
            $engine->approve($turn, User::findOrFail($turn->approver_id), null);
        }
        if ($i === 0) {
            if (! $this->authoriser) {
                throw new RuntimeException('No approval rule and no authoriser for '.class_basename($doc).'.');
            }
            $fallback($this->authoriser);
        }
    }

    private function at(string $when): void
    {
        $t = Carbon::parse($when, 'Africa/Nairobi');
        Carbon::setTestNow($t);
        CarbonImmutable::setTestNow($t);
    }

    private function dioceseOf(Territory $place): ?Territory
    {
        for ($p = $place; $p; $p = $p->parent_territory_id ? Territory::find($p->parent_territory_id) : null) {
            if ($p->territory_type->value === 'diocese') {
                return $p;
            }
        }

        return null;
    }

    private function code(string $code): int
    {
        return (int) \App\Models\AccountingAccount::whereNull('territory_id')->where('code', $code)->value('id');
    }

    private function fund(string $code): int
    {
        return (int) \App\Models\AccountingFund::where('code', $code)->value('id');
    }

    private function model(int $id): \App\Models\AccountingAccount
    {
        return \App\Models\AccountingAccount::findOrFail($id);
    }

    /** Put back the leaders' start dates moved for the demo (also after a crash). */
    public static function restoreRoles(Territory $church): void
    {
        $saved = ($church->fresh()->metadata ?? [])[self::ROLES_KEY] ?? null;
        if (! is_array($saved)) {
            return;
        }
        foreach ($saved as $id => $from) {
            DB::table('user_territory_assignments')->where('id', (int) $id)->update(['effective_from' => $from]);
        }
        self::remember($church, self::ROLES_KEY, null);
    }

    private static function remember(Territory $church, string $key, mixed $value): void
    {
        $metadata = $church->fresh()->metadata ?? [];
        if ($value === null) {
            unset($metadata[$key]);
        } else {
            $metadata[$key] = $value;
        }
        Territory::withoutAuditing(fn () => Territory::whereKey($church->id)->update(['metadata' => json_encode($metadata)]));
    }

    /** @return array<string, int> */
    private function maxIds(): array
    {
        return collect(array_keys(self::TABLES))->mapWithKeys(fn ($t) => [$t => (int) DB::table($t)->max('id')])->all();
    }
}
