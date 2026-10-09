<?php

namespace App\Services\Accounting;

use App\Models\AccountingAccount;
use App\Models\AccountingFund;
use App\Models\BudgetLine;
use App\Models\Territory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The chart of accounts (docs/specs/accounting-spec.md): one standard chart
 * set by the diocese and shared by every church, region and diocese, with
 * each place's own bank and M-Pesa accounts under its headers. The income
 * and expense accounts follow the standard budget lines one to one, so a
 * budget line always knows where its money posts.
 */
final class Chart
{
    /**
     * code => [name, type, options]. Options: header, parent, key (system_key),
     * cash (cash_kind), line (the budget line slug it stands for), about.
     */
    public const STANDARD = [
        '1000' => ['Cash and bank', 'asset', ['header' => true]],
        '1010' => ['Cash at hand', 'asset', ['parent' => '1000', 'key' => 'cash_at_hand', 'cash' => 'cash', 'about' => 'Notes and coins kept by the treasurer']],
        '1020' => ['Petty cash', 'asset', ['parent' => '1000', 'key' => 'petty_cash', 'cash' => 'petty_cash', 'about' => 'The small float for day-to-day spending']],
        '1100' => ['Bank accounts', 'asset', ['parent' => '1000', 'header' => true, 'key' => 'banks', 'cash' => 'bank', 'about' => 'Each place adds its own bank accounts here']],
        '1150' => ['M-Pesa accounts', 'asset', ['parent' => '1000', 'header' => true, 'key' => 'mpesa', 'cash' => 'mpesa', 'about' => 'Each place adds its till, paybill or phone here']],
        '1200' => ['Staff advances', 'asset', ['key' => 'staff_advances', 'about' => 'Money given out ahead, still to be accounted for']],
        '1300' => ['Due from places below', 'asset', ['key' => 'due_from_below', 'about' => 'What churches and regions owe us']],
        '1400' => ['Other receivables', 'asset', ['key' => 'receivables']],
        '1500' => ['Fixed assets', 'asset', ['key' => 'fixed_assets', 'about' => 'Land, buildings, vehicles and equipment']],

        '2100' => ['Suppliers payable', 'liability', ['key' => 'suppliers_payable', 'about' => 'Bills received, not yet paid']],
        '2200' => ['Due to the diocese', 'liability', ['key' => 'due_to_diocese']],
        '2210' => ['Due to the region', 'liability', ['key' => 'due_to_region']],
        '2300' => ['Payroll deductions payable', 'liability', ['key' => 'payroll_deductions', 'about' => 'PAYE, NSSF, SHIF and Housing Levy held to pay over']],
        '2310' => ['Net pay payable', 'liability', ['key' => 'net_pay']],
        '2400' => ['Money held for others', 'liability', ['key' => 'held_for_others', 'about' => 'Collected on behalf of someone else, to pass on']],

        '3000' => ['General fund', 'fund', ['key' => 'general_fund', 'about' => 'Money free to be used for any of the church\'s work']],
        '3100' => ['Building fund', 'fund', ['key' => 'building_fund', 'about' => 'Kept for building only']],
        '3200' => ['KYS fund', 'fund', ['key' => 'kys_fund', 'about' => 'Kingdom Youth Summit']],
        '3300' => ['Conference fund', 'fund', ['key' => 'conference_fund', 'about' => 'Kept for conferences']],

        '4000' => ['Tithes', 'income', ['line' => 'tithes']],
        '4010' => ['Offerings', 'income', ['line' => 'offerings']],
        '4020' => ['Special collections', 'income', ['line' => 'special-collections']],
        '4030' => ['Donations', 'income', ['line' => 'donations']],
        '4040' => ['Fundraising events', 'income', ['line' => 'fundraising-events']],
        '4050' => ['Grants', 'income', ['line' => 'grants']],
        '4100' => ['Church contributions', 'income', ['line' => 'church-contributions', 'about' => 'Shares sent up by the places below']],
        '4110' => ['Diocesan allocations', 'income', ['line' => 'diocesan-allocations']],
        '4120' => ['Regional allocations', 'income', ['line' => 'regional-allocations']],
        '4200' => ['Rental income', 'income', ['line' => 'rental-income']],
        '4210' => ['Investment income', 'income', ['line' => 'investment-income']],
        '4900' => ['Other income', 'income', ['line' => 'other-income', 'key' => 'other_income']],

        '5000' => ['Salaries & wages', 'expense', ['line' => 'salaries-wages']],
        '5010' => ['Staff housing', 'expense', ['line' => 'staff-housing']],
        '5020' => ['Pastoral support', 'expense', ['line' => 'pastoral-support']],
        '5100' => ['Ministry programs', 'expense', ['line' => 'ministry-programs']],
        '5110' => ['Youth ministry', 'expense', ['line' => 'youth-ministry']],
        '5120' => ['Women ministry', 'expense', ['line' => 'women-ministry']],
        '5130' => ['Men ministry', 'expense', ['line' => 'men-ministry']],
        '5140' => ['Children ministry', 'expense', ['line' => 'children-ministry']],
        '5150' => ['Music & worship', 'expense', ['line' => 'music-worship']],
        '5200' => ['Events & conferences', 'expense', ['line' => 'events-conferences']],
        '5210' => ['Retreats', 'expense', ['line' => 'retreats']],
        '5220' => ['Training & development', 'expense', ['line' => 'training-development']],
        '5300' => ['Building maintenance', 'expense', ['line' => 'building-maintenance']],
        '5310' => ['Equipment', 'expense', ['line' => 'equipment']],
        '5320' => ['Cleaning services', 'expense', ['line' => 'cleaning-services']],
        '5330' => ['Security services', 'expense', ['line' => 'security-services']],
        '5400' => ['Electricity', 'expense', ['line' => 'utilities-electricity']],
        '5410' => ['Water', 'expense', ['line' => 'utilities-water']],
        '5420' => ['Internet', 'expense', ['line' => 'utilities-internet']],
        '5430' => ['Communications', 'expense', ['line' => 'communications']],
        '5500' => ['Office supplies', 'expense', ['line' => 'office-supplies']],
        '5510' => ['Insurance', 'expense', ['line' => 'insurance']],
        '5520' => ['Legal & professional fees', 'expense', ['line' => 'legal-professional-fees']],
        '5530' => ['Travel & transport', 'expense', ['line' => 'travel-transportation']],
        '5600' => ['Charitable activities', 'expense', ['line' => 'charitable-activities']],
        '5610' => ['Emergency fund', 'expense', ['line' => 'emergency-fund']],
        '5700' => ['Diocesan tithe', 'expense', ['line' => 'diocesan-tithe', 'about' => 'The share sent to the diocese']],
        '5710' => ['Regional levy', 'expense', ['line' => 'regional-levy']],
        '5800' => ['Bank & M-Pesa charges', 'expense', ['line' => 'bank-charges', 'key' => 'bank_charges']],
        '5950' => ['Cash shortage / over', 'expense', ['key' => 'cash_short_over', 'about' => 'Differences found when cash is counted']],
        '5990' => ['Other expenses', 'expense', ['line' => 'other-expenses', 'key' => 'other_expense']],
    ];

    /** code => [name, restricted, equity account code, about] */
    public const FUNDS = [
        'GEN' => ['General fund', false, '3000', 'For any of the church\'s work'],
        'BLD' => ['Building fund', true, '3100', 'Kept for building only'],
        'KYS' => ['KYS fund', true, '3200', 'Kingdom Youth Summit'],
        'CONF' => ['Conference fund', true, '3300', 'Kept for conferences'],
    ];

    /** Where a cash-kind's own accounts go: kind => [header code, default name] */
    private const PLACE_KINDS = [
        'bank' => ['1100', 'Bank'],
        'mpesa' => ['1150', 'M-Pesa'],
    ];

    /** @var array<string, AccountingAccount> */
    private array $byKey = [];

    /** Checked once per request (Chart is a scoped singleton - AppServiceProvider). */
    private bool $ensured = false;

    /** Write the standard chart and funds, and map the standard budget lines. Safe to run again. */
    public function ensureStandard(): void
    {
        if ($this->ensured) {
            return;
        }
        $this->ensured = true;
        if (AccountingAccount::whereNull('territory_id')->count() >= count(self::STANDARD) && AccountingFund::count() >= count(self::FUNDS)
            && ! BudgetLine::whereNull('account_id')->whereIn('slug', $this->lineSlugs())->exists()) {
            return;
        }
        DB::transaction(function () {
            $ids = [];
            $order = 0;
            foreach (self::STANDARD as $code => [$name, $type, $o]) {
                $account = AccountingAccount::firstOrNew(['territory_id' => null, 'code' => (string) $code]);
                $account->fill([
                    'parent_id' => isset($o['parent']) ? ($ids[$o['parent']] ?? null) : null,
                    'type' => $type,
                    'system_key' => $o['key'] ?? null,
                    'cash_kind' => $o['cash'] ?? null,
                    'is_header' => (bool) ($o['header'] ?? false),
                    'display_order' => $order += 10,
                ]);
                if (! $account->exists) {
                    // The name and description are the diocese's to change later; only set them once.
                    $account->fill(['name' => $name, 'description' => $o['about'] ?? null, 'is_active' => true]);
                }
                $account->save();
                $ids[(string) $code] = $account->id;
                if (isset($o['line'])) {
                    BudgetLine::where('slug', $o['line'])->whereNull('account_id')->update(['account_id' => $account->id]);
                }
            }
            $order = 0;
            foreach (self::FUNDS as $code => [$name, $restricted, $equity, $about]) {
                $fund = AccountingFund::firstOrNew(['code' => $code]);
                $fund->fill(['is_restricted' => $restricted, 'equity_account_id' => $ids[$equity] ?? null, 'display_order' => $order += 10]);
                if (! $fund->exists) {
                    $fund->fill(['name' => $name, 'description' => $about, 'is_active' => true]);
                }
                $fund->save();
            }
        });
        $this->byKey = [];
    }

    private function lineSlugs(): array
    {
        return array_values(array_filter(array_map(fn ($a) => $a[2]['line'] ?? null, self::STANDARD)));
    }

    /** A standard account by its system key (cash_at_hand, other_income...). */
    public function account(string $key): AccountingAccount
    {
        if (! isset($this->byKey[$key])) {
            $this->ensureStandard();
            $this->byKey[$key] = AccountingAccount::whereNull('territory_id')->where('system_key', $key)->firstOrFail();
        }

        return $this->byKey[$key];
    }

    public function generalFund(): AccountingFund
    {
        $this->ensureStandard();

        return AccountingFund::where('code', 'GEN')->firstOrFail();
    }

    /** The account a budget line posts to: its own, or Other income / Other expenses. */
    public function forBudgetLine(BudgetLine $line): AccountingAccount
    {
        $this->ensureStandard();
        if ($line->account_id && ($account = AccountingAccount::find($line->account_id))) {
            return $account;
        }

        return $this->account($line->budgetCategory?->slug === 'income' ? 'other_income' : 'other_expense');
    }

    /** The budget line of this place that posts to an account (the first, when several do). */
    public function budgetLineFor(Territory $place, int $accountId): ?BudgetLine
    {
        return BudgetLine::where('account_id', $accountId)->where('is_active', true)
            ->forPlace($place->territory_type->value, $place->id)
            ->orderByRaw('territory_id IS NULL')->orderBy('display_order')->first();
    }

    /** The accounts a place can post to (standard + its own), headers left out. */
    public function usable(Territory $place): Collection
    {
        $this->ensureStandard();

        return AccountingAccount::usableBy($place->id)->where('is_active', true)->where('is_header', false)
            ->orderBy('code')->get();
    }

    /** The place's money accounts: Cash at hand, Petty cash and its own banks and M-Pesa. */
    public function cashAccounts(Territory $place, bool $activeOnly = true): Collection
    {
        $this->ensureStandard();

        return AccountingAccount::usableBy($place->id)->whereNotNull('cash_kind')->where('is_header', false)
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->orderBy('code')->get();
    }

    /** The place's first bank / M-Pesa account - made ("Bank", "M-Pesa") the first time it's needed. */
    public function placeAccount(Territory $place, string $kind, ?int $by = null): AccountingAccount
    {
        if ($kind === 'cash' || $kind === 'petty_cash') {
            return $this->account($kind === 'cash' ? 'cash_at_hand' : 'petty_cash');
        }
        $existing = AccountingAccount::where('territory_id', $place->id)->where('cash_kind', $kind)->where('is_header', false)
            ->orderByDesc('is_active')->orderBy('code')->first();
        if ($existing) {
            return $existing;
        }

        return $this->addPlaceAccount($place, $kind, ['name' => self::PLACE_KINDS[$kind][1]], $by);
    }

    /** Add one of a place's own money accounts under its header, numbered 1100-01, 1100-02... */
    public function addPlaceAccount(Territory $place, string $kind, array $data, ?int $by = null): AccountingAccount
    {
        if (! isset(self::PLACE_KINDS[$kind])) {
            throw ValidationException::withMessages(['cash_kind' => ['Add a bank or an M-Pesa account.']]);
        }
        $this->ensureStandard();
        [$headerCode] = self::PLACE_KINDS[$kind];
        $header = AccountingAccount::whereNull('territory_id')->where('code', $headerCode)->firstOrFail();

        return DB::transaction(function () use ($place, $kind, $data, $by, $header, $headerCode) {
            $n = AccountingAccount::where('territory_id', $place->id)->where('parent_id', $header->id)->lockForUpdate()->count() + 1;
            do {
                $code = $headerCode.'-'.str_pad((string) $n++, 2, '0', STR_PAD_LEFT);
            } while (AccountingAccount::where('territory_id', $place->id)->where('code', $code)->exists());

            return AccountingAccount::create([
                'territory_id' => $place->id,
                'parent_id' => $header->id,
                'code' => $code,
                'name' => trim($data['name']),
                'description' => $data['description'] ?? null,
                'type' => 'asset',
                'cash_kind' => $kind,
                'bank_name' => $data['bank_name'] ?? null,
                'branch' => $data['branch'] ?? null,
                'account_number' => $data['account_number'] ?? null,
                'mpesa_number' => $data['mpesa_number'] ?? null,
                'is_active' => true,
                'display_order' => $header->display_order,
                'created_by' => $by,
            ]);
        });
    }
}
