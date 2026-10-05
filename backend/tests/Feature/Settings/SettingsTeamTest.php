<?php

namespace Tests\Feature\Settings;

use App\Models\Church;
use App\Models\Diocese;
use App\Models\Permission;
use App\Models\Region;
use App\Models\Role;
use App\Models\SuperAdminConfig;
use App\Models\User;
use App\Models\UserTerritoryAssignment;
use App\Support\Settings\SettingsRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Settings > Leadership & team (docs/specs/settings-spec.md, S2): who can
 * add whom, one-time credentials, and the guards on yourself and the last
 * manager. Uses the real role names, since "below your own role" follows
 * SettingsAccess::TEAM_ROLES.
 */
class SettingsTeamTest extends TestCase
{
    use RefreshDatabase;

    protected Church $church;

    protected Church $otherChurch;

    protected array $roles = [];

    protected User $pastor;

    protected User $admin;

    protected User $secretary;

    protected function setUp(): void
    {
        parent::setUp();
        $diocese = Diocese::create(['name' => 'Test Diocese', 'code' => 'T-DIO', 'territory_type' => 'diocese', 'level' => 1]);
        $region = Region::create(['name' => 'Region A', 'code' => 'T-RA', 'territory_type' => 'region', 'level' => 2, 'parent_territory_id' => $diocese->id]);
        $this->church = Church::create(['name' => 'My Church', 'code' => 'MY-CH', 'territory_type' => 'church', 'level' => 4, 'parent_territory_id' => $region->id]);
        $this->otherChurch = Church::create(['name' => 'Other Church', 'code' => 'OT-CH', 'territory_type' => 'church', 'level' => 4, 'parent_territory_id' => $region->id]);

        $managers = ['Senior Pastor', 'Church Administrator'];
        foreach (['Senior Pastor', 'Church Administrator', 'Associate Pastor', 'Church Secretary', 'Elder'] as $name) {
            $role = Role::create(['name' => $name, 'guard_name' => 'web', 'territory_level' => 'church']);
            foreach (in_array($name, $managers, true) ? ['read', 'manage'] : ['read'] as $action) {
                $role->givePermissionTo(Permission::firstOrCreate(
                    ['name' => SettingsRegistry::permission('church', 'team', $action), 'guard_name' => 'web'],
                    ['action' => $action, 'territory_scope' => 'church'],
                ));
            }
            $this->roles[$name] = $role;
        }
        Role::create(['name' => 'Regional Overseer', 'guard_name' => 'web', 'territory_level' => 'region']);

        $this->pastor = $this->member('pastor', 'Senior Pastor', '+254 700 000 001');
        $this->admin = $this->member('admin', 'Church Administrator', '+254 700 000 002');
        $this->secretary = $this->member('secretary', 'Church Secretary', '+254 700 000 003');
    }

    private function member(string $username, string $role, ?string $phone = null, ?Church $at = null): User
    {
        $user = User::create([
            'firstname' => 'Test', 'lastname' => ucfirst($username), 'username' => "test.{$username}",
            'email' => "test.{$username}@example.test", 'phone' => $phone, 'password' => bcrypt('password'),
        ]);
        $user->assignRole($this->roles[$role]);
        UserTerritoryAssignment::create([
            'user_id' => $user->id, 'territory_id' => ($at ?? $this->church)->id, 'role_id' => $this->roles[$role]->id,
            'assignment_type' => 'primary', 'is_active' => true,
            'effective_from' => now()->subDay(), 'assigned_by' => $user->id, 'assigned_at' => now()->subDay(),
        ]);

        return $user;
    }

    private function assignmentOf(User $user): UserTerritoryAssignment
    {
        return UserTerritoryAssignment::where('user_id', $user->id)->where('territory_id', $this->church->id)->where('is_active', true)->firstOrFail();
    }

    public function test_the_team_lists_people_most_senior_first_with_what_i_can_do(): void
    {
        Sanctum::actingAs($this->admin);
        $data = $this->getJson('/api/settings/team')->assertOk()->json('data');

        $this->assertSame(['Senior Pastor', 'Church Administrator', 'Church Secretary'], array_column(array_column($data['people'], 'role'), 'name'));
        $this->assertFalse($data['people'][0]['can']['change'], "an administrator can't touch the senior pastor");
        $this->assertTrue($data['people'][1]['is_me']);
        $this->assertTrue($data['people'][2]['can']['remove']);
        $this->assertSame(['Associate Pastor', 'Church Secretary', 'Elder'], array_column($data['grantable'], 'name'));
        $this->assertSame(2, $data['counts']['managers']);
    }

    public function test_a_senior_pastor_adds_someone_new_and_sees_the_password_once(): void
    {
        Sanctum::actingAs($this->pastor);

        $res = $this->postJson('/api/settings/team', [
            'firstname' => 'Mary', 'lastname' => 'Mwende', 'phone' => '0712 345 678', 'role_id' => $this->roles['Church Secretary']->id,
        ])->assertCreated()->assertJsonPath('data.existing', false);

        $creds = $res->json('data.credentials');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $creds['employee_code']);
        $this->assertSame(10, strlen($creds['temporary_password']));
        $mary = User::where('employee_code', $creds['employee_code'])->firstOrFail();
        $this->assertTrue(Hash::check($creds['temporary_password'], $mary->password));
        $this->assertTrue((bool) $mary->must_change_password);
        $this->assertTrue($mary->hasRole('Church Secretary'));
        $list = $this->getJson('/api/settings/team')->getContent();
        $this->assertStringNotContainsString($creds['temporary_password'], $list);
        $this->assertStringNotContainsString($creds['employee_code'], $list, 'the code alone signs in, so it stays out of the list');

        // The new person signs in with their code and the temporary password, or their code alone.
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['identifier' => $creds['employee_code'], 'password' => $creds['temporary_password']])
            ->assertOk()->assertJsonPath('success', true);
        $this->assertMatchesRegularExpression('/^\d{4}$/', $creds['pin']);
        $this->postJson('/api/auth/login-code', ['employee_code' => $creds['employee_code']])
            ->assertOk()->assertJsonPath('success', true);
        $this->assertDatabaseHas('audits', ['event' => 'settings.team', 'auditable_id' => $this->church->id]);
    }

    public function test_someone_already_in_the_system_gets_a_new_role_not_a_second_account(): void
    {
        $elsewhere = $this->member('visitor', 'Elder', '0722 111 222', $this->otherChurch);
        Sanctum::actingAs($this->pastor);

        $this->postJson('/api/settings/team', [
            'firstname' => 'Someone', 'lastname' => 'Else', 'phone' => '+254 722 111 222', 'role_id' => $this->roles['Elder']->id,
        ])->assertCreated()->assertJsonPath('data.existing', true)->assertJsonPath('data.credentials', null);

        $this->assertSame(1, User::where('phone', '0722 111 222')->count());
        $this->assertSame('secondary', $this->assignmentOf($elsewhere)->assignment_type->value);

        $this->postJson('/api/settings/team', ['firstname' => 'X', 'lastname' => 'Y', 'email' => $elsewhere->email, 'role_id' => $this->roles['Elder']->id])
            ->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_nobody_gives_a_role_at_or_above_their_own_or_from_another_level(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/settings/team', ['firstname' => 'A', 'lastname' => 'B', 'phone' => '0711000111', 'role_id' => $this->roles['Senior Pastor']->id])
            ->assertStatus(422)->assertJsonValidationErrors(['role_id']);
        $this->postJson('/api/settings/team', ['firstname' => 'A', 'lastname' => 'B', 'phone' => '0711000111', 'role_id' => $this->roles['Church Administrator']->id])
            ->assertStatus(422);
        $this->postJson('/api/settings/team', ['firstname' => 'A', 'lastname' => 'B', 'phone' => '0711000111', 'role_id' => Role::firstWhere('name', 'Regional Overseer')->id])
            ->assertStatus(422);
    }

    public function test_a_reader_cannot_manage(): void
    {
        Sanctum::actingAs($this->secretary);

        $this->getJson('/api/settings/team')->assertOk()->assertJsonPath('data.can.manage', false)->assertJsonPath('data.grantable', []);
        $this->postJson('/api/settings/team', ['firstname' => 'A', 'lastname' => 'B', 'phone' => '0711000111', 'role_id' => $this->roles['Elder']->id])->assertForbidden();
    }

    public function test_roles_change_and_people_are_removed_below_you_but_never_yourself(): void
    {
        Sanctum::actingAs($this->admin);
        $secretary = $this->assignmentOf($this->secretary);

        $this->putJson("/api/settings/team/{$secretary->id}", ['role_id' => $this->roles['Elder']->id])->assertOk();
        $this->assertSame($this->roles['Elder']->id, $secretary->fresh()->role_id);
        $this->assertTrue($this->secretary->fresh()->hasRole('Elder'));
        $this->assertFalse($this->secretary->fresh()->hasRole('Church Secretary'));

        $this->putJson('/api/settings/team/'.$this->assignmentOf($this->admin)->id, ['role_id' => $this->roles['Elder']->id])->assertStatus(422);
        $this->deleteJson('/api/settings/team/'.$this->assignmentOf($this->admin)->id)->assertStatus(422);
        $this->deleteJson('/api/settings/team/'.$this->assignmentOf($this->pastor)->id)->assertForbidden();

        $this->deleteJson("/api/settings/team/{$secretary->id}")->assertOk();
        $this->assertFalse((bool) $secretary->fresh()->is_active);
        $this->assertNotNull(User::find($this->secretary->id), 'the account stays');
    }

    public function test_the_last_manager_cannot_be_removed_or_moved_down(): void
    {
        $globalAdmin = User::create(['firstname' => 'G', 'lastname' => 'A', 'username' => 'test.global', 'email' => 'g@example.test', 'password' => bcrypt('password')]);
        SuperAdminConfig::create(['user_id' => $globalAdmin->id, 'primary_territory_id' => $this->church->id, 'global_access' => true]);
        Sanctum::actingAs($globalAdmin);
        $q = "?territory_id={$this->church->id}";

        $this->deleteJson('/api/settings/team/'.$this->assignmentOf($this->admin)->id.$q)->assertOk(); // two managers - fine
        $this->deleteJson('/api/settings/team/'.$this->assignmentOf($this->pastor)->id.$q)->assertStatus(422);
        $this->putJson('/api/settings/team/'.$this->assignmentOf($this->pastor)->id.$q, ['role_id' => $this->roles['Elder']->id])->assertStatus(422);
    }

    public function test_reset_access_gives_a_new_password_and_the_old_one_stops_working(): void
    {
        $this->secretary->createToken('old');
        Sanctum::actingAs($this->pastor);

        $oldCode = $this->secretary->employee_code;
        $creds = $this->postJson('/api/settings/team/'.$this->assignmentOf($this->secretary)->id.'/reset-access')->assertOk()->json('data.credentials');

        $fresh = $this->secretary->fresh();
        $this->assertNotSame($oldCode, $creds['employee_code']);
        $this->assertSame($creds['employee_code'], $fresh->employee_code);
        $this->assertTrue(Hash::check($creds['temporary_password'], $fresh->password));
        $this->assertTrue(Hash::check($creds['pin'], $fresh->pin));
        $this->assertFalse(Hash::check('password', $fresh->password));
        $this->assertTrue((bool) $fresh->must_change_password);
        $this->assertSame(0, $fresh->tokens()->count());
    }

    public function test_another_churchs_people_are_out_of_reach(): void
    {
        $other = $this->member('otherelder', 'Elder', null, $this->otherChurch);
        Sanctum::actingAs($this->pastor);

        $id = UserTerritoryAssignment::where('user_id', $other->id)->value('id');
        $this->deleteJson("/api/settings/team/{$id}")->assertNotFound();
    }

    // S6a: adding people safely ------------------------------------------------

    public function test_phones_are_saved_one_way_and_numbers_that_are_not_kenyan_mobiles_are_refused(): void
    {
        Sanctum::actingAs($this->pastor);
        $add = fn (string $phone, string $last) => $this->postJson('/api/settings/team', ['firstname' => 'Jane', 'lastname' => $last, 'phone' => $phone, 'role_id' => $this->roles['Elder']->id]);

        $add('0712 345 678', 'One')->assertCreated();
        $this->assertSame('+254712345678', User::where('lastname', 'One')->value('phone'));
        $add('254 113 456 789', 'Two')->assertCreated();
        $this->assertSame('+254113456789', User::where('lastname', 'Two')->value('phone'));
        $add('0201234567', 'Three')->assertStatus(422)->assertJsonPath('errors.phone.0', 'Use a Kenyan mobile number, e.g. 0712 345 678.');
    }

    public function test_the_check_shows_who_has_a_number_masked_and_blocks_clashes(): void
    {
        $other = $this->member('other', 'Associate Pastor', '+254 711 111 111', $this->otherChurch);
        Sanctum::actingAs($this->pastor);

        $d = $this->getJson('/api/settings/team/check?phone=0711111111')->assertOk()->json('data');
        $this->assertSame('+254711111111', $d['phone']['normalized']);
        $this->assertSame('Test Other', $d['match']['name']);
        $this->assertSame([['role' => 'Associate Pastor', 'place' => 'Other Church']], $d['match']['roles']);
        $this->assertSame('+254 7•• ••• 111', $d['match']['phone_masked']);
        $this->assertSame('t•••@example.test', $d['match']['email_masked']);
        $this->assertFalse($d['match']['on_this_team']);
        $this->assertNull($d['conflict'], 'someone from another church can be given a role here');
        $this->assertStringNotContainsString('test.other@example.test', json_encode($d));

        $this->assertStringContainsString('already on the team here', $this->getJson('/api/settings/team/check?phone=0700000003')->json('data.conflict'));

        $mixed = $this->getJson('/api/settings/team/check?phone=0711111111&email=test.secretary@example.test')->json('data.conflict');
        $this->assertSame('This phone number belongs to Test Other and this email to Test Secretary - they can\'t both be the same person.', $mixed);
        $this->postJson('/api/settings/team', ['firstname' => 'X', 'lastname' => 'Y', 'phone' => '0711111111', 'email' => 'test.secretary@example.test', 'role_id' => $this->roles['Elder']->id])
            ->assertStatus(422)->assertJsonPath('errors.phone.0', $mixed);

        $this->postJson('/api/settings/team', ['firstname' => 'X', 'lastname' => 'Y', 'phone' => '0799999999', 'email' => 'test.other@example.test', 'role_id' => $this->roles['Elder']->id])
            ->assertStatus(422)->assertJsonPath('errors.phone.0', 'This email belongs to Test Other, whose phone ends in 111. Use their number, or a different email.');
        $this->assertSame(1, UserTerritoryAssignment::where('user_id', $other->id)->count());
    }

    public function test_the_check_is_for_people_who_can_manage_the_team(): void
    {
        Sanctum::actingAs($this->secretary);
        $this->getJson('/api/settings/team/check?phone=0711111111')->assertForbidden();
    }

    public function test_no_two_people_share_a_phone_however_it_is_written(): void
    {
        $this->assertSame('700000001', $this->pastor->fresh()->phone_key);
        $rule = fn (?int $ignore) => \Illuminate\Support\Facades\Validator::make(['phone' => '0700 000 001'], ['phone' => [new \App\Rules\UniquePhone($ignore)]]);
        $this->assertTrue($rule(null)->fails());
        $this->assertSame('This phone number is already used by Test Pastor.', $rule(null)->errors()->first('phone'));
        $this->assertFalse($rule($this->pastor->id)->fails(), 'keeping your own number is fine');

        $this->expectException(\Illuminate\Database\QueryException::class);
        User::create(['firstname' => 'Dup', 'lastname' => 'Licate', 'username' => 'dup', 'email' => 'dup@example.test', 'phone' => '0700000001', 'password' => bcrypt('x')]);
    }

    public function test_each_role_on_offer_says_what_it_is(): void
    {
        Sanctum::actingAs($this->pastor);
        $elder = collect($this->getJson('/api/settings/team')->json('data.grantable'))->firstWhere('name', 'Elder');
        $this->assertSame('A church elder, supporting the pastors.', $elder['blurb']);
    }
}
