<?php

namespace App\Services\Accounting;

use App\Services\Settings\Settings;

/**
 * Kenyan statutory deductions on one month's pay (docs/specs/accounting-spec.md,
 * A7), from the rates in Settings > Payroll - they change by law, so nothing
 * here is fixed:
 *  - NSSF: the rate on pay up to the lower earnings limit (Tier I) and between
 *    it and the upper limit (Tier II); the employer matches it.
 *  - SHIF: a % of gross, with a minimum. Housing Levy: a % of gross; the
 *    employer matches it.
 *  - PAYE: the bands on pay after NSSF, SHIF and the Housing Levy (deductible
 *    since December 2024), less personal relief - never below zero.
 */
final class PayrollCalculator
{
    /** Setting key => default (the rates in force in October 2026). */
    public const DEFAULTS = [
        'payroll.paye_upto_1' => 24000, 'payroll.paye_rate_1' => 10,
        'payroll.paye_upto_2' => 32333, 'payroll.paye_rate_2' => 25,
        'payroll.paye_upto_3' => 500000, 'payroll.paye_rate_3' => 30,
        'payroll.paye_upto_4' => 800000, 'payroll.paye_rate_4' => 32.5,
        'payroll.paye_rate_5' => 35,
        'payroll.relief' => 2400,
        'payroll.nssf_rate' => 6, 'payroll.nssf_lel' => 9000, 'payroll.nssf_uel' => 108000,
        'payroll.shif_rate' => 2.75, 'payroll.shif_min' => 300,
        'payroll.ahl_rate' => 1.5,
    ];

    /** @var array<string, float> */
    private array $rates = [];

    public function __construct(private Settings $settings) {}

    public function rate(string $key): float
    {
        return $this->rates[$key] ??= (float) ($this->settings->system($key) ?? self::DEFAULTS[$key]);
    }

    /**
     * @return array{gross: float, nssf: float, shif: float, ahl: float, taxable: float, paye: float, employer_nssf: float, employer_ahl: float}
     */
    public function compute(float $gross, bool $statutory = true): array
    {
        $gross = round(max($gross, 0), 2);
        if (! $statutory || $gross <= 0) {
            return ['gross' => $gross, 'nssf' => 0.0, 'shif' => 0.0, 'ahl' => 0.0, 'taxable' => $gross, 'paye' => 0.0, 'employer_nssf' => 0.0, 'employer_ahl' => 0.0];
        }
        $nssf = $this->nssf($gross);
        $shif = round(max($gross * $this->rate('payroll.shif_rate') / 100, $this->rate('payroll.shif_min')), 2);
        $ahl = round($gross * $this->rate('payroll.ahl_rate') / 100, 2);
        $taxable = round(max($gross - $nssf - $shif - $ahl, 0), 2);

        return [
            'gross' => $gross, 'nssf' => $nssf, 'shif' => $shif, 'ahl' => $ahl, 'taxable' => $taxable,
            'paye' => $this->paye($taxable), 'employer_nssf' => $nssf, 'employer_ahl' => $ahl,
        ];
    }

    /** Tier I on pay up to the lower limit, Tier II between the limits. */
    public function nssf(float $gross): float
    {
        $rate = $this->rate('payroll.nssf_rate') / 100;
        $lel = $this->rate('payroll.nssf_lel');
        $uel = max($this->rate('payroll.nssf_uel'), $lel);

        return round(min($gross, $lel) * $rate + max(min($gross, $uel) - $lel, 0) * $rate, 2);
    }

    /** The bands on taxable pay, less personal relief. */
    public function paye(float $taxable): float
    {
        $tax = 0;
        $from = 0;
        foreach ([1, 2, 3, 4] as $b) {
            $upto = $this->rate("payroll.paye_upto_{$b}");
            if ($taxable > $from) {
                $tax += (min($taxable, $upto) - $from) * $this->rate("payroll.paye_rate_{$b}") / 100;
            }
            $from = $upto;
        }
        if ($taxable > $from) {
            $tax += ($taxable - $from) * $this->rate('payroll.paye_rate_5') / 100;
        }

        return round(max($tax - $this->rate('payroll.relief'), 0), 2);
    }
}
