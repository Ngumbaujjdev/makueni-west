<?php

namespace Tests\Feature\Diocese;

use App\Models\Church;
use App\Models\Diocese;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SuperAdminConfig;
use App\Models\User;
use App\Models\UserTerritoryAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The access-control APIs need a System Administration permission of the
 * role the user is acting in (docs/specs/settings-spec.md, S0) - they used
 * to need only a login.
 */
class AdminEndpointsAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN = 'diocesesettings.systemadministration.';

    protected Diocese $diocese;

    protected Church $church;

    protected Role $pastorRole;

    protected User $pastor;

    protected User $userManager;

    protected User $globalAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->diocese = Diocese::create(['name' => 'Test Diocese', 'code' => 'T-DIO', 'territory_type' => 'diocese', 'level' => 1]);
        $this->church = Church::create(['name' => 'My Church', 'code' => 'MY-CH', 'territory_type' => 'church', 'level' => 4, 'parent_territory_id' => $this->diocese->id]);

        [$this->pastor, $this->pastorRole] = $this->userWithRole('pastor', 'Test Pastor', 'church', $this->church->id, ['church.dashboard.dashboardoverview.read']);
        [$this->userManager] = $this->userWithRole('manager', 'Test Diocese Secretary', 'diocese', $this->diocese->id, [
            self::ADMIN.'usermanagement.read', self::ADMIN.'usermanagement.update',
        ]);
        [$this->globalAdmin] = $this->userWithRole('admin', 'Test Global Administrator', 'diocese', $this->diocese->id, []);
        SuperAdminConfig::create(['user_id' => $this->globalAdmin->id, 'primary_territory_id' => $this->diocese->id, 'global_access' => true]);
    }

    /** Admin-only routes a church pastor used to reach with just a login. */
    public static function adminRoutes(): array
    {
        return [
            'list roles' => ['GET', '/api/roles'],
            'create role' => ['POST', '/api/roles'],
            'change a role\'s permissions' => ['PUT', '/api/roles/{role}/permissions'],
            'list permissions' => ['GET', '/api/permissions'],
            'create permission' => ['POST', '/api/permissions'],
            'list modules' => ['GET', '/api/modules'],
            'create module' => ['POST', '/api/modules'],
            'create module group' => ['POST', '/api/module-groups'],
            'list users' => ['GET', '/api/users'],
            'create user' => ['POST', '/api/users'],
            'create assignment' => ['POST', '/api/user-assignments'],
            'update church' => ['PUT', '/api/churches/{church}'],
            'create territory' => ['POST', '/api/territories'],
            'someone else\'s login history' => ['GET', '/api/auth/user/{other}/audits/login-history'],
        ];
    }

    #[DataProvider('adminRoutes')]
    public function test_a_church_pastor_is_refused(string $method, string $path): void
    {
        Sanctum::actingAs($this->pastor);

        $this->json($method, $this->url($path))->assertForbidden();
    }

    #[DataProvider('adminRoutes')]
    public function test_a_global_admin_gets_past_the_check(string $method, string $path): void
    {
        Sanctum::actingAs($this->globalAdmin);

        // Writes with an empty body fail validation (422) - past the gate either way.
        $this->assertNotSame(403, $this->json($method, $this->url($path))->status());
    }

    public function test_everyone_keeps_their_sidebar_and_their_own_records(): void
    {
        Sanctum::actingAs($this->pastor);

        $this->getJson('/api/modules/for-role')->assertOk();
        $this->getJson("/api/users/{$this->pastor->id}")->assertOk();
        $this->getJson("/api/auth/user/{$this->pastor->id}/audits/login-history")->assertOk();
        $this->getJson('/api/churches')->assertOk();
        $this->getJson("/api/churches/{$this->church->id}")->assertOk();
    }

    public function test_a_user_manager_can_manage_users_but_not_roles(): void
    {
        Sanctum::actingAs($this->userManager);

        $this->getJson('/api/users')->assertOk();
        $this->getJson('/api/roles')->assertOk(); // the user pages list roles
        $this->assertNotSame(403, $this->postJson('/api/users', [])->status());
        $this->putJson("/api/roles/{$this->pastorRole->id}/permissions", ['permission_ids' => []])->assertForbidden();
        $this->postJson('/api/modules', [])->assertForbidden();
    }

    public function test_it_is_the_acting_role_that_counts_not_every_role_held(): void
    {
        // The pastor also sits on the diocese with user management rights.
        $dioceseRole = Role::firstWhere('name', 'Test Diocese Secretary');
        $this->pastor->assignRole($dioceseRole);
        $dioceseAssignment = UserTerritoryAssignment::create([
            'user_id' => $this->pastor->id, 'territory_id' => $this->diocese->id, 'role_id' => $dioceseRole->id,
            'assignment_type' => 'secondary', 'is_active' => true,
            'effective_from' => now()->subDay(), 'assigned_by' => $this->pastor->id, 'assigned_at' => now()->subDay(),
        ]);
        $churchAssignment = UserTerritoryAssignment::where('user_id', $this->pastor->id)->where('territory_id', $this->church->id)->first();
        Sanctum::actingAs($this->pastor);

        $this->getJson('/api/users', ['X-Assignment-Id' => $churchAssignment->id])->assertForbidden();
        $this->getJson('/api/users', ['X-Assignment-Id' => $dioceseAssignment->id])->assertOk();
    }

    private function url(string $path): string
    {
        return strtr($path, [
            '{role}' => (string) $this->pastorRole->id,
            '{church}' => (string) $this->church->id,
            '{other}' => (string) $this->userManager->id,
        ]);
    }

    /** @return array{0: User, 1: Role} */
    private function userWithRole(string $username, string $roleName, string $level, int $territoryId, array $permissions): array
    {
        $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web'], ['territory_level' => $level]);
        foreach ($permissions as $name) {
            $role->givePermissionTo(Permission::firstOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
                ['action' => substr(strrchr($name, '.'), 1), 'territory_scope' => $level],
            ));
        }
        $user = User::create([
            'firstname' => 'Test', 'lastname' => ucfirst($username), 'username' => "test.{$username}",
            'email' => "test.{$username}@example.test", 'password' => bcrypt('password'),
        ]);
        $user->assignRole($role);
        UserTerritoryAssignment::create([
            'user_id' => $user->id, 'territory_id' => $territoryId, 'role_id' => $role->id,
            'assignment_type' => 'primary', 'is_active' => true,
            'effective_from' => now()->subDay(), 'assigned_by' => $user->id, 'assigned_at' => now()->subDay(),
        ]);

        return [$user, $role];
    }
}
