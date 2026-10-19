<?php

namespace Tests\Feature\Mobile;

use App\Models\UserTerritoryAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Settings\BuildsSettingsWorld;
use Tests\TestCase;

/**
 * The phone app's sign-in (docs/specs/mobile-app-spec.md, M1-M4): phone as
 * well as email, the diocese code, the app's own token, auth/me and the
 * acting role's modules.
 */
class AppSignInTest extends TestCase
{
    use BuildsSettingsWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSettingsWorld();
        $this->pastor->update(['status' => 'active', 'phone' => '+254711222333', 'employee_code' => '483920']);
    }

    private function login(array $body)
    {
        return $this->postJson('/api/auth/login', $body + ['password' => 'password']);
    }

    public function test_a_phone_number_signs_in_in_any_common_form(): void
    {
        foreach (['0711222333', '+254711222333', '254711222333', '0711 222 333'] as $phone) {
            $this->login(['identifier' => $phone])->assertJsonPath('success', true)
                ->assertJsonPath('data.user.id', $this->pastor->id);
        }
        $this->login(['identifier' => 'test.pastor@example.test'])->assertJsonPath('success', true);
    }

    public function test_the_diocese_code_must_be_the_persons_diocese(): void
    {
        $this->login(['identifier' => 'test.pastor', 'diocese_code' => 't-dio'])->assertJsonPath('success', true);
        $this->login(['identifier' => 'test.pastor', 'diocese_code' => 'OTHER'])
            ->assertJsonPath('success', false)->assertJsonPath('status', 401)->assertJsonPath('message', 'Check the diocese code.');
        $this->postJson('/api/auth/login-code', ['employee_code' => '483920', 'diocese_code' => 'OTHER'])->assertJsonPath('status', 401);
        $this->postJson('/api/auth/login-code', ['employee_code' => '483920', 'diocese_code' => 'T-DIO'])->assertJsonPath('success', true);
    }

    public function test_a_wrong_password_still_says_invalid_before_the_code_is_looked_at(): void
    {
        $this->postJson('/api/auth/login', ['identifier' => 'test.pastor', 'password' => 'nope', 'diocese_code' => 'OTHER'])
            ->assertJsonPath('status', 401)->assertJsonPath('message', 'Invalid credentials');
    }

    public function test_a_new_app_sign_in_ends_the_last_app_one_but_not_the_web(): void
    {
        $this->login(['identifier' => 'test.pastor']);                       // web
        $this->login(['identifier' => 'test.pastor', 'client' => 'app']);    // app 1
        $this->login(['identifier' => 'test.pastor', 'client' => 'app']);    // app 2 replaces app 1
        $names = $this->pastor->tokens()->pluck('name')->sort()->values()->all();
        $this->assertSame(['Api-Access', 'app'], $names);
    }

    public function test_me_gives_the_acting_role(): void
    {
        // The pastor also sits on the region, as a second role.
        $second = UserTerritoryAssignment::create([
            'user_id' => $this->pastor->id, 'territory_id' => $this->region->id, 'role_id' => $this->overseer->roles()->first()->id,
            'assignment_type' => 'secondary', 'is_active' => true,
            'effective_from' => now()->subDay(), 'assigned_by' => $this->pastor->id, 'assigned_at' => now()->subDay(),
        ]);
        $token = $this->login(['identifier' => 'test.pastor', 'client' => 'app'])->json('data.token');

        $this->withToken($token)->getJson('/api/auth/me')->assertJsonPath('data.current_role.territory_type', 'church')
            ->assertJsonCount(2, 'data.territorial_roles');
        $this->withToken($token)->withHeader('X-Assignment-Id', (string) $second->id)->getJson('/api/auth/me')
            ->assertJsonPath('data.current_role.assignment_id', $second->id)
            ->assertJsonPath('data.current_role.territory_type', 'region');
    }

    public function test_me_needs_a_token(): void
    {
        $this->getJson('/api/auth/me')->assertStatus(401);
    }

    public function test_password_sign_in_is_throttled(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/auth/login', ['identifier' => 'test.pastor', 'password' => 'wrong']);
        }
        $this->postJson('/api/auth/login', ['identifier' => 'test.pastor', 'password' => 'password'])->assertStatus(429);
    }

    public function test_the_employee_code_reset_is_throttled(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/password-reset/request-employee-code', ['email' => 'nobody@example.test']);
        }
        $this->postJson('/api/password-reset/request-employee-code', ['email' => 'nobody@example.test'])->assertStatus(429);
    }

    public function test_the_app_may_call_the_api_from_another_origin(): void
    {
        $this->call('OPTIONS', '/api/auth/login', [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'x-assignment-id,authorization,content-type',
        ])->assertHeader('Access-Control-Allow-Origin');
    }
}
