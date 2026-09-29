<?php

namespace App\Reports\Demographics;

use App\Reports\ReportColumn;
use App\Reports\ReportContext;
use App\Reports\ReportData;
use App\Reports\ReportSection;
use App\Support\Reports\Insights\InsightEngine;
use App\Support\Reports\Insights\Rules\DeparturesVsNewMembersRule;
use App\Support\Reports\Insights\Rules\GenderBalanceRule;
use App\Support\Reports\Insights\Rules\HolyCommunionRule;
use App\Support\Reports\Insights\Rules\MembershipTrendRule;
use App\Support\Reports\Insights\Rules\ReportingGapsRule;
use App\Support\Reports\Insights\Rules\SacramentsRule;
use App\Support\Reports\Insights\Rules\SundaySchoolTeachersRule;
use App\Support\Reports\Insights\Rules\YouthShareRule;
use Illuminate\Validation\ValidationException;

/**
 * One metric's report - what Export gives you on that metric's page
 * (church/demographics-growth/metric.php?key=...). Same keys as the page's
 * DEMOGRAPHIC_METRICS. Metrics recorded by gender (total members, Sunday
 * school) show both parts and the total. Defaults to all time, like the page.
 */
final class MetricReport extends FiscalYearReport
{
    /** key => [label, field (null = sum of parts), parts [[field, label]], flow] */
    public const METRICS = [
        'total_members' => ['Total members', 'total_members', [['male_count', 'Male'], ['female_count', 'Female']], false],
        'youth' => ['Youth (13-35)', 'youth_count', [], false],
        'womens_fellowship' => ["Women's fellowship", 'womens_fellowship_count', [], false],
        'mens_fellowship' => ["Men's fellowship", 'mens_fellowship_count', [], false],
        'sunday_school' => ['Sunday school', null, [['sunday_school_male_count', 'Boys'], ['sunday_school_female_count', 'Girls']], false],
        'seniors' => ['Seniors (60+)', 'seniors_count', [], false],
        'new_members' => ['New members', 'new_members_count', [], true],
        'departures' => ['Departures', 'transferred_out_count', [], true],
        'baptisms' => ['Baptisms', 'baptisms_count', [], true],
        'communion' => ['Holy Communion', 'communion_participants_count', [], true],
        'conversions' => ['Conversions', 'conversions_count', [], true],
    ];

    private ?string $metric = null;

    public function key(): string
    {
        return 'demographics.metric';
    }

    public function title(): string
    {
        return $this->metric ? self::METRICS[$this->metric][0] : 'Metric report';
    }

    public function titleFor(array $params): string
    {
        return self::has($params['metric'] ?? null) ? self::METRICS[$params['metric']][0] : $this->title();
    }

    public function description(): string
    {
        return 'One figure over time, the report behind each metric page.';
    }

    public function icon(): string
    {
        return 'ri-line-chart-line';
    }

    public function inputs(): array
    {
        return ['fiscal_year', 'metric'];
    }

    public function lockedOnly(): bool
    {
        return true;
    }

    public static function has(?string $metric): bool
    {
        return $metric !== null && isset(self::METRICS[$metric]);
    }

    private function rulesFor(string $metric, bool $allTime): array
    {
        $rules = match ($metric) {
            'total_members' => [new MembershipTrendRule, GenderBalanceRule::members()],
            'youth' => [new YouthShareRule],
            'sunday_school' => [GenderBalanceRule::sundaySchool(), new SundaySchoolTeachersRule],
            'new_members', 'departures' => [new DeparturesVsNewMembersRule],
            'baptisms', 'conversions' => [new SacramentsRule],
            'communion' => [new HolyCommunionRule],
            default => [],
        };

        return $allTime ? $rules : [new ReportingGapsRule, ...$rules];
    }

    public function build(ReportContext $context): ReportData
    {
        $metric = $context->param('metric');
        if (! self::has($metric)) {
            throw ValidationException::withMessages(['metric' => 'Choose a metric to report on.']);
        }
        $this->metric = $metric;
        // A metric page shows the whole history, so its report does too unless a year is chosen.
        if ($context->param('fiscal_year_id') === null) {
            $context->params['fiscal_year_id'] = 'all';
        }
        [$label, $field, $parts, $flow] = self::METRICS[$metric];

        [$periods, $periodLabel] = $this->scope($context);
        $mode = $this->data->mode($context);
        $allTime = $this->isAllTime($context);
        $missing = $allTime ? [] : $this->missingLabels($periods);

        $value = function (array $p) use ($field, $parts) {
            if ($field !== null) {
                return DemographicsData::int($p[$field] ?? null);
            }
            $values = array_map(fn ($part) => $p[$part[0]] ?? null, $parts);

            return count(array_filter($values, fn ($v) => $v !== null)) === 0 ? null : (int) array_sum($values);
        };

        // Rows: parts (if any), the value, and the change from the last reported period.
        $rows = [];
        $previous = null;
        foreach ($periods as $p) {
            $v = $value($p);
            $change = $v !== null && $previous !== null ? ($v - $previous > 0 ? '+' : '').number_format($v - $previous) : '-';
            $rows[] = [$p['label'], ...array_map(fn ($part) => DemographicsData::int($p[$part[0]] ?? null), $parts), $v, $change];
            if ($v !== null) {
                $previous = $v;
            }
        }

        $total = $flow ? 'sum' : 'latest';
        $columns = [
            ReportColumn::text('Period', true),
            ...array_map(fn ($part) => ReportColumn::number($part[1], $total), $parts),
            ReportColumn::number($parts ? 'Total' : $label, $total, true),
            ReportColumn::number('Change'),
        ];

        $values = array_values(array_filter(array_map($value, $periods), fn ($v) => $v !== null));
        $reportedPeriods = array_values(array_filter($periods, fn ($p) => $value($p) !== null));
        $latestPeriod = $reportedPeriods === [] ? null : end($reportedPeriods);
        $fmt = fn ($v) => $v === null ? '-' : number_format($v);

        if ($parts && $latestPeriod) {
            $a = (int) ($latestPeriod[$parts[0][0]] ?? 0);
            $b = (int) ($latestPeriod[$parts[1][0]] ?? 0);
            $shareB = $a + $b ? (int) round($b / ($a + $b) * 100) : null;
            $tiles = [
                ['label' => 'Latest total', 'value' => $fmt($value($latestPeriod)), 'tone' => 'primary'],
                ['label' => $parts[0][1], 'value' => $fmt($a), 'tone' => 'primary'],
                ['label' => $parts[1][1], 'value' => $fmt($b), 'tone' => 'pink'],
                ['label' => "{$parts[0][1]} / {$parts[1][1]}", 'value' => $shareB === null ? '-' : (100 - $shareB)."% / {$shareB}%", 'tone' => 'purple'],
            ];
        } else {
            $hi = $values === [] ? null : max($values);
            $lo = $values === [] ? null : min($values);
            $tiles = [
                ['label' => 'Latest', 'value' => $fmt($values === [] ? null : end($values)), 'tone' => 'primary'],
                ['label' => 'Highest', 'value' => $fmt($hi), 'tone' => 'success'],
                ['label' => 'Lowest', 'value' => $fmt($lo), 'tone' => 'danger'],
                $flow
                    ? ['label' => 'Total', 'value' => $fmt($values === [] ? null : array_sum($values)), 'tone' => 'purple']
                    : ['label' => 'Average', 'value' => $values === [] ? '-' : number_format(array_sum($values) / count($values)), 'tone' => 'purple'],
            ];
        }

        $totalsLabel = $flow ? ($allTime ? 'All-time total' : 'Year total') : 'Latest';
        $note = trim(($missing === [] ? '' : 'Not reported: '.implode(', ', $missing).'. ').($flow ? '' : 'The last row is the latest reported figure.'));

        return new ReportData(
            kicker: $context->kicker($this->subject()),
            title: $label,
            periodLabel: $periodLabel,
            scopeLabel: $context->scopeLabel(),
            tiles: $tiles,
            meta: $this->meta($context, $periodLabel, $mode, $periods),
            sections: [new ReportSection('By period', $columns, $rows, $note ?: null, $totalsLabel)],
            insights: InsightEngine::run($this->rulesFor($metric, $allTime), DemographicsData::facts($periods, $missing, $mode)),
        );
    }
}
