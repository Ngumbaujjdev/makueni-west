<?php

namespace App\Support\Reports\Insights\Rules;

use App\Support\Reports\Insights\Insight;
use App\Support\Reports\Insights\InsightRule;
use App\Support\Reports\Insights\ReportFacts;

/** Children per Sunday school teacher in the latest period. Facts: `latest`. */
final class SundaySchoolTeachersRule implements InsightRule
{
    public function evaluate(ReportFacts $facts): ?Insight
    {
        $latest = $facts->get('latest');
        if (! $latest || ($latest['sunday_school_teachers_count'] ?? null) === null) {
            return null;
        }
        $children = (int) ($latest['sunday_school_male_count'] ?? 0) + (int) ($latest['sunday_school_female_count'] ?? 0);
        $teachers = (int) $latest['sunday_school_teachers_count'];
        if ($children === 0) {
            return null;
        }
        if ($teachers === 0) {
            return new Insight(Insight::CONCERN, 'No Sunday school teachers recorded', "{$children} children and no teachers.", 'Recruit and train Sunday school teachers.');
        }
        $ratio = (int) round($children / $teachers);
        if ($ratio > 25) {
            return new Insight(
                Insight::CONCERN,
                "{$ratio} children per Sunday school teacher",
                "{$children} children and {$teachers} teachers.",
                'Recruit and train more Sunday school teachers - around 15 children per teacher keeps classes manageable.',
            );
        }
        if ($ratio <= 15) {
            return new Insight(Insight::GOOD, "{$ratio} children per Sunday school teacher", "{$children} children and {$teachers} teachers - a healthy class size.");
        }

        return null;
    }
}
