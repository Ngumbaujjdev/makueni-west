<?php

namespace Tests\Feature\Accounting;

use App\Models\AccountingAccount;
use App\Models\User;
use App\Services\Accounting\Chart;
use Tests\Feature\Financial\BuildsBudgetWorld;

/**
 * The budget world plus the people who keep the books
 * (docs/specs/accounting-spec.md): a church treasurer (receipts, vouchers,
 * paying, accounts, journals), the pastor who authorises, an overseer who
 * reads the churches below and authorises at the region, and the treasurer
 * of another church.
 */
trait BuildsBooks
{
    use BuildsBudgetWorld;

    protected User $treasurer;

    protected User $authoriser;

    protected User $regionReader;

    protected User $otherTreasurer;

    protected Chart $chart;

    protected function buildBooks(): void
    {
        $this->buildBudgetWorld();
        $all = ['read', 'receipt', 'prepare', 'pay', 'accounts', 'journal'];
        $this->treasurer = $this->userWithRole('treasurer', 'Church Treasurer', 'church', $this->myChurch->id, $this->perms('church', $all));
        $this->authoriser = $this->userWithRole('senior', 'Senior Pastor', 'church', $this->myChurch->id, $this->perms('church', ['read', 'authorise']));
        $this->regionReader = $this->userWithRole('regtreasurer', 'Regional Treasurer', 'region', $this->region->id, $this->perms('region', [...$all, 'below']));
        $this->otherTreasurer = $this->userWithRole('othertreasurer', 'Church Treasurer', 'church', $this->otherChurch->id, []);
        $this->chart = app(Chart::class);
        $this->chart->ensureStandard();
    }

    /** The permission names for these abilities, as AccountingAccessSeeder grants them. */
    protected function perms(string $level, array $abilities): array
    {
        $map = [
            'read' => ['accounting.books.read', 'accounting.accounts.read', 'accounting.cashbook.read', 'accounting.payments.read', 'accounting.documents.read'],
            'receipt' => ['accounting.receipts.create'],
            'prepare' => ['accounting.payments.prepare'],
            'authorise' => ['accounting.payments.authorise'],
            'pay' => ['accounting.payments.pay'],
            'journal' => ['accounting.journals.post'],
            'accounts' => ['accounting.accounts.manage'],
            'chart' => ['accounting.chart.manage'],
            'below' => ['accounting.below.read'],
        ];

        return collect($abilities)->flatMap(fn ($a) => array_map(fn ($p) => "{$level}.{$p}", $map[$a]))->all();
    }

    protected function acc(string $code): AccountingAccount
    {
        return AccountingAccount::whereNull('territory_id')->where('code', $code)->firstOrFail();
    }

    protected function cash(): AccountingAccount
    {
        return $this->chart->account('cash_at_hand');
    }

    /** A date this year, safely in the past. */
    protected function day(int $month = 1, int $day = 10): string
    {
        $d = now()->setDate(now()->year, $month, $day);

        return ($d->isFuture() ? now()->subDay() : $d)->toDateString();
    }
}
