<?php

namespace Tests\Unit;

use App\Services\Sms\Sms;
use App\Support\Phone;
use PHPUnit\Framework\TestCase;

/** Phone numbers for SMS: one Kenyan form, and a clear reason when a number won't do. */
class PhoneTest extends TestCase
{
    public function test_kenyan_numbers_in_any_form_become_one(): void
    {
        foreach (['+254757140682', '+254 757 140 682', '0757140682', '757140682', '254757140682', '0112 345 678'] as $n) {
            $this->assertNull(Phone::problem($n), $n);
            $this->assertMatchesRegularExpression('/^\+254[17]\d{8}$/', Sms::kenya($n), $n);
        }
    }

    public function test_a_digit_too_many_or_too_few_is_caught_and_explained(): void
    {
        $this->assertNull(Sms::kenya('+2547571410682'));
        $this->assertSame('A Kenyan number has 9 digits after +254 (like +254 712 345 678) - this one has 10.', Phone::problem('+2547571410682'));
        $this->assertSame('A Kenyan number has 9 digits after +254 (like +254 712 345 678) - this one has 8.', Phone::problem('075714068'));
        $this->assertSame('A Kenyan mobile number starts with 7 or 1 after +254 (like +254 712 345 678).', Phone::problem('+254 857 140 682'));
        $this->assertSame('Enter a phone number.', Phone::problem(''));
        $this->assertStringStartsWith("That doesn't look like a phone number", Phone::problem('hello'));
    }

    public function test_another_country_s_number_with_its_plus_still_goes(): void
    {
        $this->assertNull(Phone::problem('+44 7911 123456'));
        $this->assertSame('+447911123456', Sms::kenya('+44 7911 123456'));
    }
}
