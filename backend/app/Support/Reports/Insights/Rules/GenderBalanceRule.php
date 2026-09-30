<?php

namespace App\Support\Reports\Insights\Rules;

use App\Support\Reports\Insights\Insight;
use App\Support\Reports\Insights\InsightRule;
use App\Support\Reports\Insights\ReportFacts;

/**
 * Flags a lopsided split between two gender fields in the latest period -
 * used for the whole congregation (male/female) and Sunday school
 * (boys/girls). Facts: `latest`.
 */
final class GenderBalanceRule implements InsightRule
{
    public function __construct(
        private string $fieldA,
        private string $fieldB,
        private string $labelA,
        private string $labelB,
        private string $subject,
        private string $recommendA,
        private string $recommendB,
    ) {}

    public static function members(): self
    {
        return new self('male_count', 'female_count', 'Men', 'Women', 'members',
            "Consider outreach aimed at women - a women's fellowship event or mentoring.",
            "Consider outreach aimed at men - a men's breakfast or fellowship can bring men in.");
    }

    public static function sundaySchool(): self
    {
        return new self('sunday_school_male_count', 'sunday_school_female_count', 'Boys', 'Girls', 'Sunday school',
            'Look at what keeps girls coming - activities and teachers they relate to.',
            'Look at what keeps boys coming - activities and male teachers they relate to.');
    }

    /** Boys and girls at an average Sunday (facts `latest` holds the averages). */
    public static function sundayChildren(): self
    {
        return new self('children_male_count', 'children_female_count', 'Boys', 'Girls', 'children on Sundays',
            'Look at what keeps girls coming - Sunday school activities and teachers they relate to.',
            'Look at what keeps boys coming - Sunday school activities and male teachers they relate to.');
    }

    public function evaluate(ReportFacts $facts): ?Insight
    {
        $latest = $facts->get('latest');
        $a = (int) ($latest[$this->fieldA] ?? 0);
        $b = (int) ($latest[$this->fieldB] ?? 0);
        if (! $latest || $a + $b < 10) {
            return null;
        }
        $shareB = (int) round($b / ($a + $b) * 100);
        if ($shareB >= 40 && $shareB <= 60) {
            return null;
        }
        [$more, $share, $recommend] = $shareB > 60
            ? [$this->labelB, $shareB, $this->recommendA]
            : [$this->labelA, 100 - $shareB, $this->recommendB];

        return new Insight(
            Insight::WATCH,
            "{$more} are {$share}% of {$this->subject}",
            "{$this->labelA} ".number_format($a)." · {$this->labelB} ".number_format($b).'.',
            $recommend,
        );
    }
}
