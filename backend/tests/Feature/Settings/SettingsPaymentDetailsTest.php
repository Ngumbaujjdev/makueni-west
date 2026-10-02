<?php

namespace Tests\Feature\Settings;

use App\Models\ModuleGroup;
use App\Models\Role;
use App\Services\Settings\Settings;
use Database\Seeders\SettingsHubSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Financial\BuildsBudgetWorld;
use Tests\TestCase;

/**
 * Settings > Payment details (docs/specs/settings-spec.md, S5): how to pay
 * a region or the diocese, each place's own (never inherited), shown to the
 * churches below on Contributions.
 */
class SettingsPaymentDetailsTest extends TestCase
{
    use BuildsBudgetWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-15');
        $this->buildBudgetWorld();
        Sanctum::actingAs($this->bishop);
        $this->postJson('/api/budget-settings/deductions', [
            'name' => 'Diocese share', 'deduction_type' => 'percentage', 'deduction_value' => 10,
            'basis' => 'lines', 'basis_line_ids' => [$this->incomeLine->id], 'applies_to_level' => 'church', 'new_line_name' => 'Diocese share',
        ])->assertCreated();
        $budget = $this->budgetFor($this->myChurch, 'active', [$this->incomeLine], 2026, 6);
        app(\App\Services\Budgets\BudgetBook::class)->record($this->pastor, $budget, ['budget_line_id' => $this->incomeLine->id, 'amount' => 1000, 'entry_date' => '2026-06-05', 'description' => 'Tithes']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function saveFor($place, string $level, array $values): void
    {
        app(Settings::class)->setMany($place, $level, 'finance', $values, [], [], $this->bishop);
    }

    public function test_a_church_sees_how_to_pay_the_place_its_share_goes_to(): void
    {
        Sanctum::actingAs($this->pastor);
        $payTo = $this->getJson('/api/budgets/contributions?year=2026')->assertOk()->json('data.pay_to');
        $this->assertCount(1, $payTo);
        $this->assertSame('Test Diocese', $payTo[0]['name']);
        $this->assertFalse($payTo[0]['set'], 'nothing filled in yet');

        $this->saveFor($this->diocese, 'diocese', [
            'finance.mpesa_type' => 'paybill', 'finance.mpesa_number' => '247247', 'finance.mpesa_account' => '{code}-SHARE',
            'finance.bank_name' => 'KCB', 'finance.bank_account_name' => 'Test Diocese', 'finance.bank_account_number' => '1234 5678 90',
            'finance.payment_note' => 'By the 5th, please.',
        ]);

        $payTo = $this->getJson('/api/budgets/contributions?year=2026')->assertOk()->json('data.pay_to.0');
        $this->assertTrue($payTo['set']);
        $this->assertSame(['type' => 'paybill', 'label' => 'Paybill', 'number' => '247247', 'account' => "{$this->myChurch->code}-SHARE"], $payTo['mpesa']);
        $this->assertSame('1234 5678 90', $payTo['bank']['account_number']);
        $this->assertSame('By the 5th, please.', $payTo['note']);
    }

    public function test_payment_details_are_never_inherited(): void
    {
        $this->saveFor($this->diocese, 'diocese', ['finance.mpesa_type' => 'paybill', 'finance.mpesa_number' => '247247']);
        $settings = app(Settings::class);

        $this->assertSame('247247', $settings->get('finance.mpesa_number', $this->diocese));
        $r = $settings->resolve('finance.mpesa_number', $this->region);
        $this->assertNull($r['value'], "a region without details doesn't show the diocese's paybill");
        $this->assertSame('default', $r['source']);

        // The same number at the region is the region's own - it isn't dropped as "what it would inherit".
        $this->saveFor($this->region, 'region', ['finance.mpesa_type' => 'paybill', 'finance.mpesa_number' => '247247']);
        $this->assertSame('own', $settings->resolve('finance.mpesa_number', $this->region)['source']);
    }

    public function test_numbers_are_checked_and_errors_name_the_field_as_the_screen_does(): void
    {
        try {
            $this->saveFor($this->diocese, 'diocese', ['finance.mpesa_number' => '24ab']);
            $this->fail('A paybill with letters was saved');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertSame('The Paybill or till number field format is invalid.', $e->errors()['finance.mpesa_number'][0]);
        }
    }

    public function test_the_overview_asks_for_payment_details_and_treasurers_can_fill_them_in(): void
    {
        foreach (['church', 'region', 'diocese'] as $level) {
            ModuleGroup::firstOrCreate(['slug' => "{$level}-settings"], ['name' => 'Settings', 'territory_scope' => $level, 'is_active' => true]);
        }
        $treasurer = Role::firstOrCreate(['name' => 'Regional Treasurer', 'guard_name' => 'web'], ['territory_level' => 'region']);
        $this->seed(SettingsHubSeeder::class);
        $this->assertTrue($treasurer->fresh()->hasPermissionTo('region.settings.hub.finance.update'));

        \App\Models\SuperAdminConfig::create(['user_id' => $this->bishop->id, 'primary_territory_id' => $this->diocese->id, 'global_access' => true]);
        Sanctum::actingAs($this->bishop->fresh());
        $item = collect($this->getJson('/api/settings/overview')->assertOk()->json('data.checklist'))->firstWhere('key', 'payment_details');
        $this->assertSame(['key' => 'payment_details', 'label' => 'Payment details', 'done' => false, 'section' => 'finance'], $item);
        $rail = collect($this->getJson('/api/settings/sections')->json('data.groups'))->flatMap(fn ($g) => $g['sections'])->keyBy('key');
        $this->assertTrue($rail['finance']['attention']);

        $this->saveFor($this->diocese, 'diocese', ['finance.bank_account_number' => '1234567890']);
        $this->assertTrue(collect($this->getJson('/api/settings/overview')->json('data.checklist'))->firstWhere('key', 'payment_details')['done']);
    }
}
