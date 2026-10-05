<?php

namespace App\Reports\Budget;

use App\Enums\TerritoryType;
use App\Reports\ReportChart;
use App\Reports\ReportColumn;
use App\Reports\ReportContext;
use App\Reports\ReportData;
use App\Reports\ReportSection;
use App\Support\BudgetAccess;

/**
 * Contributions for a year (docs/specs/budgets-spec.md → Finance follow-up):
 * what a place sends up - received, due, sent, still to send, month by
 * month - and, for a region or the diocese, each church below. Same figures
 * as the Contributions page (BudgetRollup).
 */
final class BudgetContributionsReport extends BudgetReport
{
    private const STATUS = ['sent' => 'Sent', 'pending' => 'Still to send', 'late' => 'Late', 'none' => 'Nothing due', 'no_budget' => 'No budget'];

    public function key(): string
    {
        return 'budget.contributions';
    }

    public function title(): string
    {
        return 'Contributions';
    }

    public function description(): string
    {
        return 'What is sent up for the year - the share due on what was received, what was sent and what is still to send, month by month.';
    }

    public function icon(): string
    {
        return 'ri-hand-coin-line';
    }

    public function subject(): string
    {
        return 'contributions report';
    }

    public function inputs(): array
    {
        return ['fiscal_year'];
    }

    public function build(ReportContext $context): ReportData
    {
        [$year] = $this->period($context);
        $rollup = app(BudgetRollup::class);
        $place = $context->territory;
        $rows = $rollup->contributionsOf($place->territory_type->value, (int) $place->id, $year, $place->territory_type === TerritoryType::CHURCH);
        $t = BudgetRollup::contributionTotals($rows);
        $isChurch = $context->type() === TerritoryType::CHURCH;
        $below = ! $isChurch && ($context->user->hasGlobalAccess() || BudgetAccess::can($context->user, 'below'))
            ? $rollup->contributionsBelow($place, $year) : null;

        $sections = [];
        if ($isChurch || $rows !== []) {
            $sections[] = new ReportSection($isChurch ? 'Month by month' : 'What we send', [
                ReportColumn::text('Period', true),
                ReportColumn::text('Share'),
                ReportColumn::money('Received'),
                ReportColumn::money('Due'),
                ReportColumn::money('Sent'),
                ReportColumn::money('Still to send'),
                ReportColumn::text('Status'),
            ], array_map(fn ($r) => [$r['label'], $r['name'], $r['received'], $r['due'], $r['sent'], $r['owed'], self::STATUS[$r['status']]], $rows),
                $rows === [] ? "No share was worked out on a budget in {$year}." : null);
        }
        if ($below !== null) {
            $groups = collect($below)->groupBy(fn ($p) => $p['group'] ?? ($context->type() === TerritoryType::DIOCESE ? 'Not in a region' : 'Churches'));
            foreach ($groups as $name => $places) {
                $sections[] = new ReportSection($groups->count() > 1 || $context->type() === TerritoryType::DIOCESE ? $name : 'Our churches', [
                    ReportColumn::text('Church', true),
                    ReportColumn::money('Due'),
                    ReportColumn::money('Sent'),
                    ReportColumn::money('Still to send'),
                    ReportColumn::number('Late periods', 'sum'),
                    ReportColumn::text('Status'),
                ], $places->map(fn ($p) => [$p['name'], $p['due'], $p['sent'], $p['owed'], $p['late'], self::STATUS[$p['status']]])->values()->all());
            }
        }

        $bt = $below !== null ? BudgetRollup::contributionTotals(array_map(fn ($p) => [...$p, 'status' => $p['late'] ? 'late' : $p['status']], $below)) : null;
        $lateChurches = $below !== null ? count(array_filter($below, fn ($p) => $p['late'] > 0)) : 0;
        $months = array_values(array_filter($rows, fn ($r) => $r['month'] !== null));

        return new ReportData(
            kicker: $context->kicker($this->subject()),
            title: $isChurch ? 'Contributions' : 'Contributions - '.($context->type() === TerritoryType::DIOCESE ? 'every church' : 'our churches'),
            periodLabel: 'Whole of '.$year,
            scopeLabel: $context->scopeLabel(),
            tiles: $bt === null ? [
                ['label' => 'Received', 'value' => self::money($t['received']), 'tone' => 'success'],
                ['label' => 'Due', 'value' => self::money($t['due']), 'tone' => 'primary'],
                ['label' => 'Sent', 'value' => self::money($t['sent']), 'tone' => 'success'],
                ['label' => 'Still to send', 'value' => self::money($t['owed']), 'tone' => $t['owed'] > 0 ? 'warning' : 'success'],
                ['label' => 'Late periods', 'value' => (string) $t['late'], 'tone' => $t['late'] ? 'danger' : 'success'],
            ] : [
                ['label' => 'Due from churches', 'value' => self::money($bt['due']), 'tone' => 'primary'],
                ['label' => 'Sent', 'value' => self::money($bt['sent']), 'tone' => 'success'],
                ['label' => 'Still to send', 'value' => self::money($bt['owed']), 'tone' => $bt['owed'] > 0 ? 'warning' : 'success'],
                ['label' => 'Churches with a late period', 'value' => (string) $lateChurches, 'tone' => $lateChurches ? 'danger' : 'success'],
            ],
            meta: $this->meta($context, ['Year' => (string) $year]),
            sections: $sections,
            charts: $isChurch && $months !== [] ? [ReportChart::bars('Due and sent, month by month', array_map(fn ($r) => substr($r['label'], 0, 3), $months), [
                ['name' => 'Due', 'tone' => 'primary', 'values' => array_column($months, 'due')],
                ['name' => 'Sent', 'tone' => 'success', 'values' => array_column($months, 'sent')],
            ])] : [],
        );
    }
}
