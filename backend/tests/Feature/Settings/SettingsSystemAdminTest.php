<?php

namespace Tests\Feature\Settings;

use App\Models\Module;
use App\Models\ModuleGroup;
use App\Models\Permission;
use App\Models\ReportRun;
use App\Models\Submodule;
use App\Models\SubSubmodule;
use App\Reports\ReportData;
use App\Services\Pdf\DioceseReportPdf;
use App\Services\Settings\Settings;
use App\Support\PasswordPolicy;
use Database\Seeders\SettingsHubSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use OwenIt\Auditing\Models\Audit;
use Tests\TestCase;

/**
 * The diocese's system settings, part two (docs/specs/settings-spec.md,
 * S4b): Security, Documents & PDF, Maintenance, Audit log and Access
 * control - each one wired into the code that reads it.
 */
class SettingsSystemAdminTest extends TestCase
{
    use BuildsSettingsWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSettingsWorld();
    }

    private function save(string $section, array $values): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs($this->globalAdmin);

        return $this->putJson("/api/settings/sections/{$section}", ['values' => $values]);
    }

    public function test_the_system_sections_are_for_global_admins_only(): void
    {
        Sanctum::actingAs($this->bishop);
        foreach (['security', 'documents', 'maintenance'] as $section) {
            $this->getJson("/api/settings/sections/{$section}")->assertForbidden();
        }
        $this->getJson('/api/settings/audit')->assertForbidden();
        $this->postJson('/api/settings/maintenance/clear-cache')->assertForbidden();
        $this->getJson('/api/settings/access')->assertForbidden();
    }

    public function test_pin_tries_and_lock_time_come_from_security(): void
    {
        $this->save('security', ['security.pin_attempts' => '5', 'security.pin_lock_minutes' => '30'])->assertOk();
        $user = $this->pastor;
        $user->setPin('4821');

        foreach (range(1, 4) as $try) {
            $this->assertFalse($user->fresh()->verifyPin('0000'));
        }
        $this->assertFalse($user->fresh()->isPinLocked(), 'four wrong PINs are allowed when the limit is five');
        $user->fresh()->verifyPin('0000');
        $locked = $user->fresh();
        $this->assertTrue($locked->isPinLocked());
        $this->assertEqualsWithDelta(30, now()->diffInMinutes($locked->pin_locked_until), 1);
    }

    public function test_password_rules_come_from_security(): void
    {
        $this->assertSame(8, PasswordPolicy::min());
        $this->assertEqualsWithDelta(now()->addMonths(6)->timestamp, PasswordPolicy::expiresAt()->timestamp, 5);

        $this->save('security', ['security.password_min' => '12', 'security.password_months' => '0'])->assertOk();
        $this->assertSame(12, PasswordPolicy::min());
        $this->assertNull(PasswordPolicy::expiresAt(), 'never');

        $this->pastor->forceFill(['password' => Hash::make('old-password'), 'must_change_password' => true])->save();
        $this->postJson('/api/auth/force-password-change', [
            'user_id' => $this->pastor->id, 'current_password' => 'old-password',
            'new_password' => 'short-pw1', 'new_password_confirmation' => 'short-pw1',
        ])->assertJsonPath('status', 422)->assertJsonPath('errors.new_password.0', 'The new password field must be at least 12 characters.');
        $this->postJson('/api/auth/force-password-change', [
            'user_id' => $this->pastor->id, 'current_password' => 'old-password',
            'new_password' => 'a-much-longer-one', 'new_password_confirmation' => 'a-much-longer-one',
        ])->assertOk();
        $this->assertNull($this->pastor->fresh()->password_expires_at);
    }

    public function test_session_length_becomes_sanctums_expiry(): void
    {
        $this->save('security', ['security.session_hours' => '24'])->assertOk();
        app(Settings::class)->applyToConfig();
        $this->assertSame(1440, config('sanctum.expiration'));

        $this->save('security', ['security.session_hours' => '0'])->assertOk();
        app(Settings::class)->applyToConfig();
        $this->assertEmpty(config('sanctum.expiration'), 'never - Sanctum treats 0 as no expiry');
    }

    public function test_report_pdfs_and_retention_use_documents(): void
    {
        $this->save('documents', [
            'documents.org_name' => 'CCI Makueni West', 'documents.org_subtitle' => 'Diocese office',
            'documents.footer_note' => 'Printed for the council', 'documents.keep_days' => '30',
        ])->assertOk();

        $pdf = new DioceseReportPdf(new ReportData('k', 'A title', 'This month', 'Everywhere'));
        $pdf->setCompression(false);
        $text = $pdf->build()->toPdfString();
        $this->assertStringContainsString('CCI Makueni West', $text);
        $this->assertStringContainsString('Diocese office', $text);
        $this->assertStringContainsString('Printed for the council', $text);
        $this->assertSame(30, ReportRun::keepDays());

        $this->save('documents', ['documents.org_name' => ''])->assertStatus(422);
    }

    public function test_the_notice_reaches_everyone_and_tools_are_audited(): void
    {
        Sanctum::actingAs($this->pastor);
        $this->getJson('/api/settings/notice')->assertOk()->assertJsonPath('data', null);

        $this->save('maintenance', ['maintenance.notice' => 'Down for updates on Saturday 6-8 am.', 'maintenance.notice_tone' => 'danger'])->assertOk();
        Sanctum::actingAs($this->pastor);
        $this->getJson('/api/settings/notice')->assertOk()
            ->assertJsonPath('data.message', 'Down for updates on Saturday 6-8 am.')
            ->assertJsonPath('data.tone', 'danger');

        DB::table('failed_jobs')->insert(['uuid' => 'x-3', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'boom', 'failed_at' => now()]);
        Sanctum::actingAs($this->globalAdmin);
        $this->postJson('/api/settings/maintenance/forget-failed')->assertOk()->assertJsonPath('message', '1 failed job(s) removed.');
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->postJson('/api/settings/maintenance/clear-cache')->assertOk();
        $this->postJson('/api/settings/maintenance/prune-reports')->assertOk();
        $this->postJson('/api/settings/maintenance/drop-database')->assertNotFound();
        $this->assertSame(3, Audit::where('event', 'settings.maintenance')->count());
    }

    public function test_the_audit_log_lists_every_places_changes_with_secrets_masked(): void
    {
        Sanctum::actingAs($this->pastor);
        $this->putJson('/api/settings/profile', ['name' => 'My Church', 'phone' => '+254 711 000 111'])->assertOk();
        $this->save('email', ['mail.host' => 'smtp.example.test', 'mail.password' => 'very-secret'])->assertOk();

        $res = $this->getJson('/api/settings/audit')->assertOk();
        $rows = collect($res->json('data.rows'));
        $this->assertCount(2, $rows);
        $this->assertStringNotContainsString('very-secret', $res->getContent());

        $email = $rows->firstWhere('section.key', 'email');
        $this->assertSame('Test Admin', $email['by']['name']);
        $this->assertSame('Test Diocese', $email['place']['name']);
        $this->assertSame('smtp.example.test', collect($email['changes'])->firstWhere('label', 'Server')['new']);
        $this->assertContains(['label' => 'Password', 'old' => null, 'new' => '••••'], $email['changes']);
        $this->assertSame('My Church', $rows->firstWhere('section.key', 'profile')['place']['name']);

        $this->assertCount(1, $this->getJson('/api/settings/audit?section=profile')->json('data.rows'));
        $this->assertCount(1, $this->getJson("/api/settings/audit?territory={$this->myChurch->id}")->json('data.rows'));
    }

    public function test_access_control_shows_only_the_pages_a_role_can_open(): void
    {
        $admin = $this->settingsUser('dioadmin', 'Test Diocese Administrator', 'diocese', $this->diocese->id, ['read']);
        $admin->roles()->first()->givePermissionTo(Permission::create([
            'name' => 'diocesesettings.systemadministration.usermanagement.read', 'guard_name' => 'web', 'action' => 'read', 'territory_scope' => 'diocese',
        ]));

        Sanctum::actingAs($admin);
        $keys = collect($this->getJson('/api/settings/sections')->json('data.groups'))->flatMap(fn ($g) => $g['sections'])->pluck('key');
        $this->assertContains('access', $keys->all());
        $this->assertNotContains('audit', $keys->all());
        $this->assertSame(['users'], collect($this->getJson('/api/settings/access')->json('data.links'))->pluck('key')->all());

        Sanctum::actingAs($this->globalAdmin);
        $links = collect($this->getJson('/api/settings/access')->assertOk()->json('data.links'));
        $this->assertSame(['users', 'roles', 'permissions', 'modules', 'groups'], $links->pluck('key')->all());
        $this->assertGreaterThan(0, $links->firstWhere('key', 'users')['count']);
        $this->assertArrayNotHasKey('permission', $links->first());
    }

    public function test_the_seeder_moves_system_administration_in_and_switches_off_the_empty_pages(): void
    {
        $group = ModuleGroup::firstOrCreate(['slug' => 'diocese-settings'], ['name' => 'Settings', 'territory_scope' => 'diocese', 'is_active' => true]);
        $old = Module::create(['module_group_id' => $group->id, 'name' => 'Diocese Settings', 'icon' => 'ri-settings-line', 'number' => 1, 'is_active' => true]);
        $general = Submodule::create(['module_id' => $old->id, 'title' => 'General Configuration', 'path' => '/diocese/settings/general', 'is_active' => true]);
        $admin = Submodule::create(['module_id' => $old->id, 'title' => 'System Administration', 'path' => '/diocese/settings/admin', 'is_active' => true]);
        $users = SubSubmodule::create(['submodule_id' => $admin->id, 'title' => 'User Management', 'path' => '/diocese/settings/admin/users', 'is_active' => true]);
        $security = SubSubmodule::create(['submodule_id' => $admin->id, 'title' => 'Security Settings', 'path' => '/diocese/settings/admin/security', 'is_active' => true]);
        $permission = Permission::create(['name' => 'diocesesettings.systemadministration.usermanagement.read', 'guard_name' => 'web', 'action' => 'read', 'territory_scope' => 'diocese', 'module_id' => $old->id, 'submodule_id' => $admin->id, 'sub_submodule_id' => $users->id]);

        $this->seed(SettingsHubSeeder::class);
        $this->seed(SettingsHubSeeder::class); // idempotent

        $settings = Module::where('name', 'Settings')->where('module_group_id', $group->id)->firstOrFail();
        $admin->refresh();
        $this->assertSame($settings->id, $admin->module_id);
        $this->assertSame('Access control', $admin->title);
        $this->assertSame('/diocese/settings/?section=access', $admin->path);
        $this->assertTrue((bool) $users->fresh()->is_active);
        $this->assertFalse((bool) $security->fresh()->is_active, 'replaced by Settings > Security');
        $this->assertFalse((bool) $general->fresh()->is_active);
        $this->assertSame($settings->id, $permission->fresh()->module_id);
        $this->assertSame('diocesesettings.systemadministration.usermanagement.read', $permission->fresh()->name, 'permission names never change');
        $this->assertFalse((bool) $old->fresh()->is_active, 'Diocese Settings is empty now');
        $this->assertTrue(Submodule::where('module_id', $settings->id)->where('path', '/diocese/settings/?section=audit')->exists());
    }
}
