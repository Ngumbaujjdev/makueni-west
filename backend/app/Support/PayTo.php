<?php

namespace App\Support;

use App\Services\Payments\Daraja;
use Illuminate\Validation\ValidationException;

/**
 * Where a payment goes (docs/specs/accounting-spec.md, payee details): the
 * same few shapes for suppliers, staff, requisitions and vouchers -
 * M-Pesa or Airtel Money (a phone), a paybill (number + account), a till, a
 * bank account, or cash. Checked by shape, kept as JSON, said in one line.
 */
final class PayTo
{
    public const METHODS = ['mpesa' => 'M-Pesa', 'airtel' => 'Airtel Money', 'paybill' => 'Paybill', 'till' => 'Till (Buy Goods)', 'bank' => 'Bank', 'cash' => 'Cash'];

    /**
     * The details, checked and tidied; null when none were given (and none are required).
     *
     * @param  array<string, mixed>|null  $in  {method, phone?, paybill_number?, paybill_account?, till_number?, bank_name?, bank_code?, bank_branch?, bank_account?, bank_account_name?}
     */
    public static function from(?array $in, string $field = 'payee', bool $required = false): ?array
    {
        $method = (string) ($in['method'] ?? '');
        if ($method === '') {
            if ($required) {
                throw ValidationException::withMessages(["{$field}.method" => ['Say how they are paid.']]);
            }

            return null;
        }
        if (! isset(self::METHODS[$method])) {
            throw ValidationException::withMessages(["{$field}.method" => ['Pick M-Pesa, Airtel Money, paybill, till, bank or cash.']]);
        }
        $v = fn (string $k, int $max = 60) => mb_substr(trim((string) ($in[$k] ?? '')), 0, $max);
        $fail = fn (string $k, string $msg) => throw ValidationException::withMessages(["{$field}.{$k}" => [$msg]]);
        $out = ['method' => $method];
        switch ($method) {
            case 'mpesa':
            case 'airtel':
                if (! Daraja::validPhone($v('phone'))) {
                    $fail('phone', 'Enter their '.self::METHODS[$method].' number, e.g. 0712 345 678.');
                }
                $out['phone'] = Daraja::phone($v('phone'));
                break;
            case 'paybill':
                if (! preg_match('/^\d{5,7}$/', $v('paybill_number'))) {
                    $fail('paybill_number', 'Enter the paybill number (5 to 7 digits).');
                }
                if ($v('paybill_account') === '') {
                    $fail('paybill_account', 'Enter the account number to type with the paybill.');
                }
                $out += ['paybill_number' => $v('paybill_number'), 'paybill_account' => $v('paybill_account', 30)];
                break;
            case 'till':
                if (! preg_match('/^\d{5,7}$/', $v('till_number'))) {
                    $fail('till_number', 'Enter the till number (5 to 7 digits).');
                }
                $out['till_number'] = $v('till_number');
                break;
            case 'bank':
                if ($v('bank_name') === '') {
                    $fail('bank_name', 'Which bank?');
                }
                if (! preg_match('/^[A-Za-z0-9\- ]{5,30}$/', $v('bank_account'))) {
                    $fail('bank_account', 'Enter the account number.');
                }
                if ($v('bank_account_name') === '') {
                    $fail('bank_account_name', 'Enter the account name as the bank has it.');
                }
                $out += ['bank_name' => $v('bank_name', 100), 'bank_code' => $v('bank_code', 20) ?: null, 'bank_branch' => $v('bank_branch', 100) ?: null,
                    'bank_account' => preg_replace('/\s+/', '', $v('bank_account', 30)), 'bank_account_name' => $v('bank_account_name', 150)];
                break;
        }

        return $out;
    }

    /** "M-Pesa 0712 345 678", "Paybill 247247, account 0123...", "KCB 0123456789 (St Mark's Hardware)", "Cash". */
    public static function describe(?array $d): ?string
    {
        if (! $d || empty($d['method'])) {
            return null;
        }
        $phone = fn ($p) => $p ? preg_replace('/^254/', '0', (string) $p) : '';

        return match ($d['method']) {
            'mpesa', 'airtel' => self::METHODS[$d['method']].' '.$phone($d['phone'] ?? ''),
            'paybill' => 'Paybill '.($d['paybill_number'] ?? '').', account '.($d['paybill_account'] ?? ''),
            'till' => 'Till '.($d['till_number'] ?? ''),
            'bank' => trim(($d['bank_name'] ?? 'Bank').' '.($d['bank_account'] ?? '').($d['bank_account_name'] ?? '' ? ' ('.$d['bank_account_name'].')' : '').($d['bank_branch'] ?? '' ? ', '.$d['bank_branch'] : '')),
            default => self::METHODS[$d['method']] ?? null,
        };
    }

    /** Validation rules for a payee array under $field. @return array<string, array> */
    public static function rules(string $field = 'payee'): array
    {
        return [
            $field => ['nullable', 'array'],
            "{$field}.method" => ['nullable', 'string', 'max:20'],
            ...collect(['phone', 'paybill_number', 'paybill_account', 'till_number', 'bank_name', 'bank_code', 'bank_branch', 'bank_account', 'bank_account_name'])
                ->mapWithKeys(fn ($k) => ["{$field}.{$k}" => ['nullable', 'string', 'max:150']])->all(),
        ];
    }
}
