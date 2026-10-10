<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * How a place takes money online (docs/specs/accounting-spec.md, A10): its
 * Paystack subaccount, or (A10b) PayHero or its own Daraja. Credentials are
 * encrypted by hand, not with an encrypted cast - a row written under an old
 * APP_KEY then reads as empty instead of breaking the page (a v1-events lesson).
 */
class PaymentChannel extends Model
{
    public const PROVIDERS = ['paystack' => 'Paystack', 'payhero' => 'PayHero', 'daraja' => 'Own Daraja paybill'];

    protected $fillable = ['territory_id', 'provider', 'status', 'subaccount_code', 'bank_code', 'bank_name', 'account_number', 'account_name', 'settles_into_id', 'credentials', 'callback_key', 'created_by'];

    protected $hidden = ['credentials', 'callback_key'];

    public function place(): BelongsTo
    {
        return $this->belongsTo(Territory::class, 'territory_id');
    }

    /** @return array<string, string> */
    public function secrets(): array
    {
        try {
            return $this->credentials ? (array) json_decode(Crypt::decryptString($this->credentials), true) : [];
        } catch (Throwable) {
            return [];
        }
    }

    public function setSecrets(array $values): void
    {
        $this->credentials = $values ? Crypt::encryptString(json_encode($values)) : null;
    }

    /** Own Daraja: environment, consumer_key, consumer_secret, passkey. PayHero: username, password, channel_id. */
    public const SECRETS = ['daraja' => ['consumer_key', 'consumer_secret', 'passkey'], 'payhero' => ['username', 'password', 'channel_id']];

    /** An M-Pesa channel (own Daraja / PayHero) with everything it needs to take money. */
    public function mpesaReady(): bool
    {
        $s = $this->secrets();

        return isset(self::SECRETS[$this->provider]) && filled($this->account_number)
            && collect(self::SECRETS[$this->provider])->every(fn ($k) => filled($s[$k] ?? null));
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
