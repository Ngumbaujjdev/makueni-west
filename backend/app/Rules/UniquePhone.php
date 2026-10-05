<?php

namespace App\Rules;

use App\Models\User;
use App\Support\Phone;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * No two people share a phone number (docs/specs/settings-spec.md, S6a) -
 * compared by the last 9 digits, so 07… and +2547… are the same number.
 */
class UniquePhone implements ValidationRule
{
    public function __construct(private ?int $ignoreUserId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $key = Phone::key(is_string($value) ? $value : null);
        if (! $key) {
            return;
        }
        $owner = User::where('phone_key', $key)->when($this->ignoreUserId, fn ($q) => $q->where('id', '!=', $this->ignoreUserId))->first();
        if ($owner) {
            $fail("This phone number is already used by {$owner->firstname} {$owner->lastname}.");
        }
    }
}
