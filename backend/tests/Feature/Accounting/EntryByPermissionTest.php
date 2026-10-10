<?php

namespace Tests\Feature\Accounting;

use App\Models\Collection;
use App\Models\GatheringCategory;
use App\Models\GatheringType;
use App\Models\Role;
use Database\Seeders\AccountingAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Entry by permission (docs/specs/accounting-spec.md): who may record is a
 * permission the diocese admin gives in Roles & permissions - nothing is
 * hard-coded. A page says who holds it here; the seeder never puts back a
 * default the admin took away; a collection says which service it was for.
 */
class EntryByPermissionTest extends TestCase
{
    use BuildsBooks, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBooks();
    }

    public function test_a_page_can_ask_who_holds_a_permission_here(): void
    {
        Sanctum::actingAs($this->authoriser);
        $d = $this->getJson('/api/accounting/holders?permission=receipts.create')->assertOk()->json('data');
        $this->assertSame('My Church', $d['place']['name']);
        $this->assertContains($this->treasurer->full_name, collect($d['holders'])->pluck('name')->all());
        $this->assertNotContains($this->authoriser->full_name, collect($d['holders'])->pluck('name')->all());
        $this->getJson('/api/accounting/holders?permission=nonsense.here')->assertNotFound();
    }

    public function test_the_seeder_never_puts_back_what_the_admin_took_away(): void
    {
        foreach (['church', 'region', 'diocese'] as $level) {
            \App\Models\ModuleGroup::firstOrCreate(['slug' => "{$level}-finance"], ['name' => 'Finance', 'territory_scope' => $level, 'order' => 3, 'icon' => 'ri-money-dollar-circle-line', 'is_active' => true]);
        }
        $this->seed(AccountingAccessSeeder::class);
        $treasurer = Role::where('name', 'Church Treasurer')->first();
        $this->assertTrue($treasurer->hasPermissionTo('church.accounting.collections.record'));
        // The admin takes it away in Roles & permissions...
        $treasurer->revokePermissionTo('church.accounting.collections.record');
        // ...and gives Senior Pastor the right to record.
        Role::where('name', 'Senior Pastor')->first()->givePermissionTo('church.accounting.collections.record');
        $this->seed(AccountingAccessSeeder::class);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertFalse($treasurer->fresh()->hasPermissionTo('church.accounting.collections.record'), 'not put back');
        $this->assertTrue(Role::where('name', 'Senior Pastor')->first()->hasPermissionTo('church.accounting.collections.record'), 'the admin\'s grant stays');
        $this->assertTrue($treasurer->fresh()->hasPermissionTo('church.accounting.payments.pay'), 'the rest of the defaults are untouched');
    }

    public function test_a_collection_says_which_service_it_was_for(): void
    {
        $category = GatheringCategory::create(['name' => 'Ministry Gathering', 'slug' => 'ministry-gathering-test', 'is_active' => true]);
        $tuesday = GatheringType::create(['gathering_category_id' => $category->id, 'territory_id' => $this->myChurch->id, 'name' => 'Tuesday Fellowship', 'slug' => 'tuesday-fellowship', 'is_active' => true]);
        $theirs = GatheringType::create(['gathering_category_id' => $category->id, 'territory_id' => $this->otherChurch->id, 'name' => 'Kesha', 'slug' => 'kesha', 'is_active' => true]);
        Sanctum::actingAs($this->treasurer);
        $services = collect($this->getJson('/api/accounting/collections/options')->assertOk()->json('data.services'))->pluck('name');
        $this->assertSame(['Sunday service', 'Tuesday Fellowship'], $services->all(), 'the church\'s own gatherings, not another church\'s');

        $body = ['date' => $this->day(1, 6), 'title' => 'Tuesday Fellowship', 'lines' => [['label' => 'Offering', 'account_id' => $this->acc('4010')->id, 'cash_amount' => 1500]]];
        $this->postJson('/api/accounting/collections', $body + ['gathering_type_id' => $theirs->id])->assertUnprocessable()->assertJsonValidationErrors('gathering_type_id');
        $id = $this->postJson('/api/accounting/collections', $body + ['gathering_type_id' => $tuesday->id])->assertCreated()->json('data.id');
        $this->assertSame([$tuesday->id, 'Tuesday Fellowship'], [(int) Collection::find($id)->gathering_type_id, Collection::find($id)->title]);
    }
}
