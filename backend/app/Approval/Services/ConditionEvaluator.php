<?php

namespace App\Approval\Services;

/**
 * A workflow's conditions (docs/specs/accounting-spec.md, A4):
 *   null | []  -> always
 *   {"and": [..]} | {"or": [..]} | {"not": node}
 *   {"field": "amount", "operator": ">", "value": 50000}
 * Strict, unlike the engine it came from: numbers are compared as numbers,
 * a field that isn't there fails every comparison (only "missing" passes),
 * and "in" compares exactly.
 */
final class ConditionEvaluator
{
    public const OPERATORS = ['=', '!=', '>', '>=', '<', '<=', 'in', 'not_in', 'between', 'missing'];

    public function passes(?array $rule, array $context): bool
    {
        if (! $rule) {
            return true;
        }
        if (isset($rule['and'])) {
            foreach ((array) $rule['and'] as $r) {
                if (! $this->passes((array) $r, $context)) {
                    return false;
                }
            }

            return true;
        }
        if (isset($rule['or'])) {
            foreach ((array) $rule['or'] as $r) {
                if ($this->passes((array) $r, $context)) {
                    return true;
                }
            }

            return false;
        }
        if (array_key_exists('not', $rule)) {
            return ! $this->passes((array) $rule['not'], $context);
        }

        return $this->leaf($rule, $context);
    }

    private function leaf(array $rule, array $context): bool
    {
        $op = $rule['operator'] ?? '=';
        $has = data_get($context, $rule['field'] ?? '', '__missing__') !== '__missing__';
        if ($op === 'missing') {
            return ! $has;
        }
        if (! $has) {
            return false;
        }
        $actual = data_get($context, $rule['field']);
        $value = $rule['value'] ?? null;

        return match ($op) {
            '=' => $this->same($actual, $value),
            '!=' => ! $this->same($actual, $value),
            '>', '>=', '<', '<=' => is_numeric($actual) && is_numeric($value) && match ($op) {
                '>' => (float) $actual > (float) $value,
                '>=' => (float) $actual >= (float) $value,
                '<' => (float) $actual < (float) $value,
                '<=' => (float) $actual <= (float) $value,
            },
            'in' => collect((array) $value)->contains(fn ($v) => $this->same($actual, $v)),
            'not_in' => ! collect((array) $value)->contains(fn ($v) => $this->same($actual, $v)),
            'between' => is_numeric($actual) && is_array($value) && count($value) === 2 && is_numeric($value[0]) && is_numeric($value[1])
                && (float) $actual >= (float) $value[0] && (float) $actual <= (float) $value[1],
            default => false,
        };
    }

    private function same(mixed $a, mixed $b): bool
    {
        if (is_numeric($a) && is_numeric($b)) {
            return abs((float) $a - (float) $b) < 0.000001;
        }
        if (is_bool($a) || is_bool($b)) {
            return (bool) $a === (bool) $b && $a !== null && $b !== null;
        }

        return (string) $a === (string) $b;
    }
}
