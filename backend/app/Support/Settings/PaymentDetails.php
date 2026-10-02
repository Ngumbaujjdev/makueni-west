<?php

namespace App\Support\Settings;

use App\Models\Territory;
use App\Services\Settings\Settings;

/**
 * How to pay a region or the diocese (Settings > Payment details, S5) -
 * shown to the churches below on Contributions, "How to send it".
 */
final class PaymentDetails
{
    /**
     * @param  Territory  $to  the place being paid
     * @param  Territory|null  $from  the place paying ({code} in the account number becomes its code)
     * @return array{id: int, name: string, type: string, set: bool, mpesa: ?array, bank: ?array, note: ?string}
     */
    public static function for(Territory $to, ?Territory $from = null): array
    {
        $settings = app(Settings::class);
        $get = fn (string $key) => ($v = $settings->get("finance.{$key}", $to)) === '' ? null : $v;

        $mpesa = $get('mpesa_type') && $get('mpesa_number') ? [
            'type' => $get('mpesa_type'),
            'label' => $get('mpesa_type') === 'till' ? 'Till (Buy Goods)' : 'Paybill',
            'number' => $get('mpesa_number'),
            'account' => $get('mpesa_type') === 'paybill' && $get('mpesa_account')
                ? str_replace('{code}', (string) ($from?->code ?? ''), $get('mpesa_account'))
                : null,
        ] : null;
        $bank = $get('bank_account_number') ? [
            'name' => $get('bank_name'),
            'branch' => $get('bank_branch'),
            'account_name' => $get('bank_account_name'),
            'account_number' => $get('bank_account_number'),
        ] : null;

        return [
            'id' => (int) $to->id,
            'name' => $to->name,
            'type' => $to->territory_type?->value ?? 'territory',
            'set' => $mpesa !== null || $bank !== null,
            'mpesa' => $mpesa,
            'bank' => $bank,
            'note' => $get('payment_note'),
        ];
    }

    /** Whether a place has filled in a way to be paid. */
    public static function isSet(Territory $place): bool
    {
        return self::for($place)['set'];
    }
}
