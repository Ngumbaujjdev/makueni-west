<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use OwenIt\Auditing\Models\Audit;
use Tests\TestCase;

/**
 * My Profile (/profile): a person changes their own details and password,
 * and reads their own activity - never someone else's.
 */
class ProfileSelfTest extends TestCase
{
    use RefreshDatabase;

    protected User $me;

    protected User $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->me = $this->person('me', '483920', '+254711000111');
        $this->other = $this->person('other', '483921', '+254722000222');
        Sanctum::actingAs($this->me);
    }

    private function person(string $name, string $code, string $phone): User
    {
        return User::create([
            'firstname' => ucfirst($name), 'lastname' => 'Tester', 'username' => "{$name}.tester",
            'email' => "{$name}@example.test", 'password' => bcrypt('old-password-1'),
            'employee_code' => $code, 'status' => 'active', 'phone' => $phone,
        ]);
    }

    public function test_a_phone_someone_else_has_is_refused_with_a_clear_message(): void
    {
        $this->putJson('/api/auth/profile', ['phone' => '0722 000 222'])
            ->assertJsonPath('success', false)
            ->assertJsonPath('status', 422)
            ->assertJsonPath('errors.phone.0', 'This phone number is already used by Other Tester.');

        $this->assertSame('+254711000111', $this->me->fresh()->phone);
    }

    public function test_a_phone_is_stored_in_one_form(): void
    {
        $this->putJson('/api/auth/profile', ['phone' => '0733 444 555', 'position' => 'Senior Pastor'])
            ->assertJsonPath('success', true);

        $this->assertSame('+254733444555', $this->me->fresh()->phone);
        $this->assertSame('Senior Pastor', $this->me->fresh()->position);
    }

    public function test_a_number_that_is_not_a_kenyan_mobile_is_refused(): void
    {
        $this->putJson('/api/auth/profile', ['phone' => '12345'])
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.phone.0', 'Enter a Kenyan mobile number, e.g. 0712 345 678.');
    }

    public function test_changing_the_password_needs_the_current_one(): void
    {
        $this->postJson('/api/password-reset/change-password', [
            'new_password' => 'new-password-22', 'new_password_confirmation' => 'new-password-22',
        ])->assertJsonPath('success', false)->assertJsonStructure(['errors' => ['current_password']]);

        $this->postJson('/api/password-reset/change-password', [
            'current_password' => 'wrong', 'new_password' => 'new-password-22', 'new_password_confirmation' => 'new-password-22',
        ])->assertJsonPath('errors.current_password.0', 'That is not your current password.');

        $this->assertTrue(Hash::check('old-password-1', $this->me->fresh()->password));

        $this->postJson('/api/password-reset/change-password', [
            'current_password' => 'old-password-1', 'new_password' => 'new-password-22', 'new_password_confirmation' => 'new-password-22',
        ])->assertJsonPath('success', true);

        $this->assertTrue(Hash::check('new-password-22', $this->me->fresh()->password));
    }

    public function test_my_activity_pages_past_a_hundred_and_a_to_date_covers_that_day(): void
    {
        foreach (range(1, 130) as $i) {
            Audit::create([
                'user_type' => $this->me->getMorphClass(), 'user_id' => $this->me->id,
                'auditable_type' => $this->me->getMorphClass(), 'auditable_id' => $this->me->id,
                'event' => 'user_login', 'old_values' => [], 'new_values' => ['login_success' => true],
                'created_at' => now()->subMinutes($i),
            ]);
        }

        $this->getJson("/api/auth/user/{$this->me->id}/audits?limit=200")
            ->assertJsonPath('success', true)->assertJsonCount(130, 'data.audits');

        $this->getJson("/api/auth/user/{$this->me->id}/audits?limit=200&to_date=".now()->toDateString())
            ->assertJsonCount(130, 'data.audits');

        $this->getJson("/api/auth/user/{$this->me->id}/audits/login-history?limit=150")
            ->assertJsonCount(130, 'data');
    }

    public function test_someone_elses_activity_is_not_mine_to_read(): void
    {
        $this->getJson("/api/auth/user/{$this->other->id}/audits")->assertStatus(403);
    }
}
