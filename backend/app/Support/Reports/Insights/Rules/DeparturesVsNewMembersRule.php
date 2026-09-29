<?php

namespace App\Support\Reports\Insights\Rules;

use App\Support\Reports\Insights\Insight;
use App\Support\Reports\Insights\InsightRule;
use App\Support\Reports\Insights\ReportFacts;

/** Departures against new members across the report. Facts: `rows`, `period_noun`. */
final class DeparturesVsNewMembersRule implements InsightRule
{
    public function evaluate(ReportFacts $facts): ?Insight
    {
        $rows = $facts->get('rows', []);
        $new = array_sum(array_map(fn ($r) => (int) ($r['new_members_count'] ?? 0), $rows));
        $out = array_sum(array_map(fn ($r) => (int) ($r['transferred_out_count'] ?? 0), $rows));
        if ($new === 0 && $out === 0) {
            return null;
        }
        $losing = count(array_filter($rows, fn ($r) => (int) ($r['transferred_out_count'] ?? 0) > (int) ($r['new_members_count'] ?? 0)));
        $noun = $facts->get('period_noun', 'period');

        if ($out > $new) {
            return new Insight(
                Insight::CONCERN,
                'More people left than joined',
                "{$out} departures against {$new} new members".($losing > 0 ? " - departures were higher in {$losing} ".($losing === 1 ? $noun : "{$noun}s").'.' : '.'),
                'Plan follow-up visits for members who transferred out, and a welcome programme to settle new members in.',
            );
        }

        return new Insight(Insight::GOOD, 'More people joined than left', "{$new} new members against {$out} departures.");
    }
}
