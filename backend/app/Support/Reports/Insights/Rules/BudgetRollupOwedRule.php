<?php

namespace App\Support\Reports\Insights\Rules;

use App\Support\Reports\Insights\Insight;
use App\Support\Reports\Insights\InsightRule;
use App\Support\Reports\Insights\ReportFacts;

/** Deductions the places below still owe (diocese share and the like). Facts (BudgetRollup): `rows`, `owed`, `words`. */
final class BudgetRollupOwedRule implements InsightRule
{
    public function evaluate(ReportFacts $facts): ?Insight
    {
        $total = (float) $facts->get('owed', 0);
        if ($total <= 0) {
            return null;
        }
        [$one, $many] = $facts->get('words', ['church', 'churches']);
        $owing = array_values(array_filter($facts->get('rows', []), fn ($r) => ($r['deductions']['owed'] ?? 0) > 0));
        usort($owing, fn ($a, $b) => $b['deductions']['owed'] <=> $a['deductions']['owed']);

        return new Insight(
            Insight::WATCH,
            'KES '.number_format($total, 2).' still owed in deductions',
            count($owing).' '.(count($owing) === 1 ? "{$one} still owes: " : "{$many} still owe: ")
                .implode('; ', array_map(fn ($r) => "{$r['name']} KES ".number_format($r['deductions']['owed'], 2), array_slice($owing, 0, 4))).'.',
            'Remind them to send it and record it as an expense on the deduction\'s line.',
        );
    }
}
