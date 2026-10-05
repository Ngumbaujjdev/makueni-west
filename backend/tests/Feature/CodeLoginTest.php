<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The employee-code sign-in: the 6-digit code alone signs a person in
 * (the PIN step added on 2026-10-02 was taken out again at the owner's
 * request on 2026-10-05). Guesses stay limited to 10 a minute.
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
            'employee_code' => '483920', 'status' => 'active',
        ]);
    }

    public function test_the_employee_code_signs_in(): void
    {
        $this->postJson('/api/auth/login-code', ['employee_code' => '483920'])
            ->assertOk()->assertJsonPath('success', true)->assertJsonStructure(['data' => ['token']]);
    }

    public function test_an_unknown_code_is_refused(): void
    {
        $this->postJson('/api/auth/login-code', ['employee_code' => '000000'])->assertJsonPath('success', false)->assertJsonPath('status', 401);
        $this->postJson('/api/auth/login-code', ['employee_code' => '12'])->assertJsonPath('success', false);
    }

    public function test_the_code_sign_in_is_rate_limited(): void
    {
        foreach (range(1, 10) as $try) {
            $this->postJson('/api/auth/login-code', ['employee_code' => '000000']);
        }
        $this->postJson('/api/auth/login-code', ['employee_code' => '483920'])->assertStatus(429);
    }
}
