<?php

namespace App\Support;

use App\Models\MonthlyReport;
use App\Models\Territory;
use App\Models\User;

/**
 * Monthly reports permissions (docs/specs/monthly-reports-spec.md):
 * {level}.reports.monthly.read / write / send for the place's own reports,
 * and {level}.reports.below.read / review for the reports of the places
 * below - through PlaceAccess, so the acting role decides.
 */
final class ReportsAccess
{
    public const ABILITIES = [
        'read' => 'reports.monthly.read',
        'write' => 'reports.monthly.write',
        'send' => 'reports.monthly.send',
        'below' => 'reports.below.read',
        'review' => 'reports.below.review',
    ];

    /** Only churches and regions write reports; the diocese reads those below. */
    public const REPORTING_LEVELS = ['church', 'region'];

    public static function can(?User $user, Territory $place, string $ability): bool
    {
        if ($ability === 'read') {
            return collect(['read', 'write', 'send'])->contains(fn ($a) => PlaceAccess::can($user, $place, self::ABILITIES, $a));
        }
        if ($ability === 'below') {
            return PlaceAccess::can($user, $place, self::ABILITIES, 'below') || PlaceAccess::can($user, $place, self::ABILITIES, 'review');
        }

        return PlaceAccess::can($user, $place, self::ABILITIES, $ability);
    }

    /** own | below | null - how the acting place relates to a report. */
    public static function relation(Territory $place, MonthlyReport $report): ?string
    {
        if ((int) $report->territory_id === (int) $place->id) {
            return 'own';
        }

        return in_array((int) $report->territory_id, PlaceAccess::descendantIds($place), true) ? 'below' : null;
    }
}
