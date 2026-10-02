<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The employee-code sign-in needs the person's PIN too. It used to sign
 * anyone in with the 6-digit code alone and no limit on guesses.
 */
class CodeLoginTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::create([
            'firstname' => 'Test', 'lastname' => 'Pastor', 'username' => 'test.pastor',
            'email' => 'test.pastor@example.test', 'password' => bcrypt('password'),
            'employee_code' => '483920', 'pin' => Hash::make('7391'), 'status' => 'active',
        ]);
    }

    public function test_the_code_and_the_right_pin_sign_in(): void
    {
        $this->postJson('/api/auth/login-code', ['employee_code' => '483920', 'pin' => '7391'])
            ->assertOk()->assertJsonPath('success', true)->assertJsonStructure(['data' => ['token']]);
    }

    public function test_the_code_alone_no_longer_signs_in(): void
    {
        $this->postJson('/api/auth/login-code', ['employee_code' => '483920'])
            ->assertJsonPath('success', false)->assertJsonStructure(['errors' => ['pin']]);
    }

    public function test_a_wrong_pin_and_an_unknown_code_get_the_same_answer(): void
    {
        $wrongPin = $this->postJson('/api/auth/login-code', ['employee_code' => '483920', 'pin' => '0000'])->json();
        $unknownCode = $this->postJson('/api/auth/login-code', ['employee_code' => '111111', 'pin' => '7391'])->json();

        $this->assertFalse($wrongPin['success']);
        $this->assertSame($wrongPin['message'], $unknownCode['message']);
        $this->assertArrayNotHasKey('token', (array) ($wrongPin['data'] ?? []));
    }

    public function test_three_wrong_pins_lock_the_pin_even_for_the_right_one(): void
    {
        foreach (range(1, 3) as $i) {
            $this->postJson('/api/auth/login-code', ['employee_code' => '483920', 'pin' => '0000']);
        }

        $this->postJson('/api/auth/login-code', ['employee_code' => '483920', 'pin' => '7391'])
            ->assertJsonPath('success', false)->assertJsonPath('status', 423);
        $this->assertTrue($this->user->fresh()->isPinLocked());

        // The password sign-in still works while the PIN is locked.
        $this->postJson('/api/auth/login', ['identifier' => 'test.pastor', 'password' => 'password'])->assertJsonPath('success', true);
    }

    public function test_someone_without_a_pin_cannot_use_the_code_sign_in(): void
    {
        $this->user->forceFill(['pin' => null])->save();

        $this->postJson('/api/auth/login-code', ['employee_code' => '483920', 'pin' => '7391'])->assertJsonPath('success', false);
    }

    public function test_the_code_sign_in_is_rate_limited(): void
    {
        foreach (range(1, 10) as $i) {
            $this->postJson('/api/auth/login-code', ['employee_code' => '1'.str_pad((string) $i, 5, '0', STR_PAD_LEFT), 'pin' => '1234']);
        }

        $this->postJson('/api/auth/login-code', ['employee_code' => '483920', 'pin' => '7391'])->assertStatus(429);
    }
}
