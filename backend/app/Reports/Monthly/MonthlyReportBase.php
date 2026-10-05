<?php

namespace App\Reports\Monthly;

use App\Models\Territory;
use App\Models\User;
use App\Reports\Report;
use App\Support\PlaceAccess;
use App\Support\ReportsAccess;

/** Monthly report exports (docs/specs/monthly-reports-spec.md): your own place's, or those below. */
abstract class MonthlyReportBase extends Report
{
    public function module(): string
    {
        return 'monthly-reports';
    }

    public function icon(): string
    {
        return 'ri-file-chart-line';
    }

    public function authorize(User $user, Territory $territory): ?string
    {
        if (! ReportsAccess::can($user, $territory, 'read') && ! ReportsAccess::can($user, $territory, 'below')) {
            return 'Your role cannot see monthly reports here.';
        }
        if (! PlaceAccess::isOwn($user, $territory) && ! PlaceAccess::isBelow($user, $territory)) {
            return 'You can export your own reports, or those of places below you.';
        }

        return null;
    }

    protected static function money(?float $value): string
    {
        return $value === null ? '-' : 'KES '.number_format($value, 2);
    }
}
