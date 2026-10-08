<?php

namespace App\Reports\Facilities;

use App\Enums\TerritoryType;
use App\Models\FiscalYear;
use App\Models\Territory;
use App\Models\User;
use App\Reports\Report;
use App\Reports\ReportContext;
use App\Services\Facilities\Facilities;
use App\Support\PeopleAccess;
use Carbon\CarbonImmutable;

/**
 * What the Facilities reports share (docs/specs/people-and-care-spec.md, P5):
 * each church's own (names and what it owns), made only by those with the
 * export permission, and - for those that take a year - its dates.
 */
abstract class FacilitiesReport extends Report
{
    public function module(): string
    {
        return 'facilities';
    }

    public function scopes(): array
    {
        return [TerritoryType::CHURCH];
    }

    public function authorize(User $user, Territory $territory): ?string
    {
        return PeopleAccess::canNamed($user, $territory, 'facilities', 'export')
            ? null
            : 'Only your own church\'s leaders with the export permission can export the facilities reports.';
    }

    protected function facilities(): Facilities
    {
        return app(Facilities::class);
    }

    /** [first day, last day, label] of the year picked (this year by default; "all" is everything up to today). */
    protected function year(ReportContext $context): array
    {
        $id = $context->param('fiscal_year_id');
        if ($id === 'all') {
            return [CarbonImmutable::create(2000, 1, 1), $this->facilities()->today(), 'all time'];
        }
        $fy = ($id && $id !== 'all' ? FiscalYear::find($id) : null) ?? FiscalYear::where('year', now()->year)->first();
        $year = (int) ($fy?->year ?? now()->year);
        $from = $fy?->start_date ? CarbonImmutable::parse($fy->start_date) : CarbonImmutable::create($year, 1, 1);
        $to = $fy?->end_date ? CarbonImmutable::parse($fy->end_date) : CarbonImmutable::create($year, 12, 31);

        return [$from, $to, (string) $year];
    }

    protected static function money(float $v): string
    {
        return 'KES '.number_format($v, 0);
    }
}
