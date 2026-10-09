<?php

namespace Tests\Feature\Accounting;

use Database\Seeders\AccountingAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Who may read and write which books (docs/specs/accounting-spec.md): your
 * own place as your role allows, the places below read-only with "below",
 * never upwards or sideways.
 */
class AccountingAccessTest extends TestCase
{
    use BuildsBooks, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBooks();
    }

    private function receiptBody(): array
    {
        return ['date' => $this->day(), 'account_id' => $this->cash()->id, 'party_name' => 'X', 'lines' => [['account_id' => $this->acc('4000')->id, 'amount' => 10]]];
    }

    public function test_a_role_without_the_ability_is_refused(): void
    {
        Sanctum::actingAs($this->authoriser);
        $this->getJson('/api/accounting/overview')->assertOk()->assertJsonPath('data.can.authorise', true)->assertJsonPath('data.can.receipt', false);
        $this->postJson('/api/accounting/receipts', $this->receiptBody())->assertForbidden();
        $this->postJson('/api/accounting/journal-vouchers', ['date' => $this->day(), 'narration' => 'x', 'lines' => []])->assertForbidden();
        $this->postJson('/api/accounting/accounts', ['cash_kind' => 'bank', 'name' => 'B'])->assertForbidden();

        Sanctum::actingAs($this->otherTreasurer);
        $this->getJson('/api/accounting/overview')->assertOk()->assertJsonPath('data.place.id', $this->otherChurch->id);
        $this->getJson("/api/accounting/overview?territory_id={$this->myChurch->id}")->assertForbidden();

        Sanctum::actingAs($this->pastor); // budgets only
        $this->getJson('/api/accounting/overview')->assertForbidden();
    }

    public function test_the_region_reads_its_churches_but_never_writes_there_and_churches_never_read_up_or_sideways(): void
    {
        Sanctum::actingAs($this->treasurer);
        $jid = $this->postJson('/api/accounting/receipts', $this->receiptBody())->assertCreated()->json('data.id');

        Sanctum::actingAs($this->regionReader);
        $this->getJson("/api/accounting/overview?territory_id={$this->myChurch->id}")->assertOk()->assertJsonPath('data.place.id', $this->myChurch->id)->assertJsonPath('data.can.receipt', false);
        $this->getJson("/api/accounting/journals/{$jid}")->assertOk();
        $this->postJson("/api/accounting/receipts?territory_id={$this->myChurch->id}", $this->receiptBody())->assertForbidden();
        $this->postJson("/api/accounting/journals/{$jid}/reverse", ['reason' => 'x'])->assertForbidden();
        $this->getJson("/api/accounting/overview?territory_id={$this->farChurch->id}")->assertForbidden();
        $this->postJson('/api/accounting/receipts', $this->receiptBody())->assertCreated()->assertJsonPath('data.number', fn ($n) => str_starts_with($n, 'T-RA/'));

        Sanctum::actingAs($this->treasurer);
        $this->getJson("/api/accounting/overview?territory_id={$this->region->id}")->assertForbidden();
        $this->getJson("/api/accounting/overview?territory_id={$this->otherChurch->id}")->assertForbidden();

        $this->otherTreasurer->roles()->first()->givePermissionTo($this->perms('church', ['read']));
        Sanctum::actingAs($this->otherTreasurer);
        $this->getJson("/api/accounting/journals/{$jid}")->assertNotFound();
    }

    public function test_the_places_picker_lists_our_books_and_those_below_only_with_below(): void
    {
        Sanctum::actingAs($this->regionReader);
        $ids = collect($this->getJson('/api/accounting/places')->assertOk()->json('data'))->pluck('id');
        $this->assertSame($this->region->id, $ids->first(), 'our own first');
        $this->assertContains($this->myChurch->id, $ids);
        $this->assertNotContains($this->farChurch->id, $ids, 'never sideways');

        Sanctum::actingAs($this->treasurer);
        $this->assertSame([$this->myChurch->id], collect($this->getJson('/api/accounting/places')->assertOk()->json('data'))->pluck('id')->all());

        Sanctum::actingAs($this->pastor);
        $this->getJson('/api/accounting/places')->assertForbidden();
    }

    public function test_only_the_diocese_changes_the_standard_chart_and_places_change_only_their_own_accounts(): void
    {
        Sanctum::actingAs($this->treasurer);
        $this->postJson('/api/accounting/chart', ['code' => '5240', 'name' => 'Fuel', 'type' => 'expense'])->assertForbidden();
        $this->putJson("/api/accounting/accounts/{$this->cash()->id}", ['name' => 'Mine'])->assertNotFound();
        $bank = $this->postJson('/api/accounting/accounts', ['cash_kind' => 'bank', 'name' => 'Co-op'])->assertCreated()->json('data.id');
        $this->postJson('/api/accounting/accounts', ['cash_kind' => 'bank', 'name' => 'Co-op'])->assertStatus(422);
        $this->putJson("/api/accounting/accounts/{$bank}", ['name' => 'Co-op Main', 'is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);

        $finance = $this->userWithRole('dfo', 'Diocese Finance Officer', 'diocese', $this->diocese->id, $this->perms('diocese', ['read', 'chart']));
        Sanctum::actingAs($finance);
        $this->postJson('/api/accounting/chart', ['code' => '4240', 'name' => 'Fuel', 'type' => 'expense'])->assertStatus(422)->assertJsonValidationErrors('code');
        $this->postJson('/api/accounting/chart', ['code' => '5240', 'name' => 'Fuel', 'type' => 'expense'])->assertCreated();
        $this->postJson('/api/accounting/chart', ['code' => '5240', 'name' => 'Fuel 2', 'type' => 'expense'])->assertStatus(422);
        $this->putJson("/api/accounting/chart/{$this->cash()->id}", ['name' => 'Cash', 'is_active' => false])->assertStatus(422);
    }

    public function test_the_seeder_builds_the_menu_and_grants_once(): void
    {
        foreach (['church', 'region', 'diocese'] as $n => $level) {
            \App\Models\ModuleGroup::firstOrCreate(['slug' => "{$level}-finance"], ['name' => 'Finance', 'territory_scope' => $level, 'order' => 3, 'icon' => 'ri-money-dollar-circle-line', 'is_active' => true]);
        }
        $this->seed(AccountingAccessSeeder::class);
        $this->seed(AccountingAccessSeeder::class);
        $this->assertSame(1, \App\Models\Module::where('name', 'Accounting')->whereHas('moduleGroup', fn ($q) => $q->where('slug', 'church-finance'))->count());
        $this->assertSame(9, \App\Models\Submodule::where('path', 'like', '/church/accounting/%')->where('is_active', true)->count());
        $this->assertSame(10, \App\Models\Submodule::where('path', 'like', '/diocese/accounting/%')->where('is_active', true)->count(), 'the chart page is the diocese\'s');
        $treasurer = \App\Models\Role::where('name', 'Church Treasurer')->first();
        $this->assertTrue($treasurer->hasPermissionTo('church.accounting.payments.pay'));
        $this->assertFalse(\App\Models\Role::where('name', 'Senior Pastor')->first()->hasPermissionTo('church.accounting.payments.pay'));
        $this->assertTrue(\App\Models\Role::where('name', 'Senior Pastor')->first()->hasPermissionTo('church.accounting.payments.authorise'));
        $this->assertSame(1, \App\Models\Permission::where('name', 'church.accounting.books.read')->count());
    }
}
