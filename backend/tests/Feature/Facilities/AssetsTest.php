<?php

namespace Tests\Feature\Facilities;

use App\Models\BudgetEntry;
use App\Models\Equipment;
use App\Models\EquipmentLoan;
use App\Models\ReportRun;
use App\Reports\Facilities\AssetRegisterReport;
use App\Reports\ReportContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Financial\BuildsBudgetWorld;
use Tests\TestCase;

/**
 * People & care, P5 round 2 (docs/specs/people-and-care-spec.md): equipment
 * as assets - asset numbers, photos behind a signed link, receipts, the
 * Budgets entry that paid for it (ours only, its receipts copied across),
 * asking to borrow and a manager's answer, what we own, and the two reports.
 */
class AssetsTest extends TestCase
{
    use BuildsBudgetWorld, RefreshDatabase;

    private $senior;

    private $deacon;

    private $exporter;

    private $otherPastor;

    protected function setUp(): void
    {
        parent::setUp();
        config(['audit.console' => true]);
        Storage::fake('local');
        $this->buildBudgetWorld();
        $f = ['church.facilities.facilities.read', 'church.facilities.facilities.book'];
        $this->senior = $this->userWithRole('senior', 'Senior Pastor', 'church', $this->myChurch->id, [...$f, 'church.facilities.facilities.manage']);
        $this->deacon = $this->userWithRole('deacon', 'Deacon', 'church', $this->myChurch->id, $f);
        $this->exporter = $this->userWithRole('treasurer', 'Church Treasurer', 'church', $this->myChurch->id, ['church.facilities.facilities.read', 'church.facilities.facilities.export']);
        $this->otherPastor = $this->userWithRole('otherpastor', 'Other Senior', 'church', $this->otherChurch->id, [...$f, 'church.facilities.facilities.manage']);
    }

    private function item(array $with = []): int
    {
        Sanctum::actingAs($this->senior);

        return $this->postJson('/api/equipment', $with + ['name' => 'Acoustic guitar', 'category' => 'instruments', 'quantity' => 2, 'value' => 15000, 'supplier' => 'Music Store, Nairobi'])->assertCreated()->json('data.id');
    }

    public function test_asset_numbers_run_per_church(): void
    {
        $a = $this->item();
        $b = $this->item(['name' => 'Keyboard']);
        Sanctum::actingAs($this->otherPastor);
        $c = $this->postJson('/api/equipment', ['name' => 'Chairs', 'category' => 'furniture'])->assertCreated()->json('data.asset_no');

        $this->assertSame(['A-0001', 'A-0002'], [Equipment::find($a)->asset_no, Equipment::find($b)->asset_no]);
        $this->assertSame('A-0001', $c);
        Sanctum::actingAs($this->senior);
        $this->getJson("/api/equipment/{$a}")->assertOk()->assertJsonPath('data.total', 30000)->assertJsonPath('data.supplier', 'Music Store, Nairobi');
    }

    public function test_photos_are_stored_upright_and_shown_only_through_a_signed_link(): void
    {
        $id = $this->item();
        Sanctum::actingAs($this->deacon);
        $this->postJson("/api/equipment/{$id}/photos", ['photos' => [UploadedFile::fake()->image('guitar.jpg', 1200, 900)]])->assertForbidden();

        Sanctum::actingAs($this->senior);
        $photos = $this->postJson("/api/equipment/{$id}/photos", ['photos' => [UploadedFile::fake()->image('a.jpg', 1200, 900), UploadedFile::fake()->image('b.png', 600, 600)]])
            ->assertCreated()->json('data.photos');
        $this->assertCount(2, $photos);
        $this->assertStringEndsWith('.webp', DB::table('equipment_photos')->value('path'));
        $this->postJson("/api/equipment/{$id}/photos", ['photos' => array_fill(0, 3, UploadedFile::fake()->image('c.jpg', 300, 300))])->assertStatus(422);

        $this->get($photos[0]['thumb_url'])->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->get(strtok($photos[0]['url'], '?'))->assertForbidden();
        $this->getJson('/api/equipment')->assertOk()->assertJsonPath('data.items.0.photo.id', $photos[0]['id']);

        $this->deleteJson("/api/equipment/{$id}/photos/{$photos[0]['id']}")->assertOk()->assertJsonCount(1, 'data.photos');
        Sanctum::actingAs($this->otherPastor);
        $this->deleteJson("/api/equipment/{$id}/photos/{$photos[1]['id']}")->assertNotFound();
    }

    public function test_receipts_and_the_budgets_entry_that_paid_for_it(): void
    {
        $id = $this->item(['value' => null]);
        $receipt = $this->postJson("/api/equipment/{$id}/receipts", ['receipt' => UploadedFile::fake()->createWithContent('receipt.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n")])
            ->assertCreated()->json('data.receipts.0');
        $this->assertSame('pdf', $receipt['type']);
        $this->get("/api/equipment/{$id}/receipts/{$receipt['id']}")->assertOk();
        Sanctum::actingAs($this->otherPastor);
        $this->get("/api/equipment/{$id}/receipts/{$receipt['id']}")->assertNotFound();

        $entry = fn ($b, $amount = 30000, $dir = 'out') => BudgetEntry::create(['budget_id' => $b->id, 'budget_line_item_id' => DB::table('budget_line_items')->where('budget_id', $b->id)->value('id'), 'direction' => $dir, 'amount' => $amount, 'entry_date' => '2026-01-10', 'description' => 'Guitars', 'recorded_by' => $this->senior->id]);
        $ours = $this->budgetFor($this->myChurch, 'active');
        $theirs = $this->budgetFor($this->otherChurch, 'active');
        Sanctum::actingAs($this->senior);
        $this->postJson("/api/equipment/{$id}/expense", ['budget_entry_id' => $entry($theirs)->id])->assertStatus(422);
        $this->postJson("/api/equipment/{$id}/expense", ['budget_entry_id' => $entry($ours, 500, 'in')->id])->assertStatus(422);
        $paid = $entry($ours);
        $this->assertContains($paid->id, array_column($this->getJson('/api/equipment/expenses?q=Guit')->assertOk()->json('data'), 'id'));

        $data = $this->postJson("/api/equipment/{$id}/expense", ['budget_entry_id' => $paid->id])->assertOk()->json('data');
        $this->assertSame($paid->id, $data['budget_entry']['id']);
        $this->assertEquals(15000, $data['value']);
        $this->assertCount(1, $paid->fresh()->getMedia('receipts'));
        $this->assertSame('recorded what it cost in Budgets', substr($data['history'][0]['sentence'], -32));

        $this->deleteJson("/api/equipment/{$id}/expense")->assertOk()->assertJsonPath('data.budget_entry', null);
        $this->deleteJson("/api/equipment/{$id}/receipts/{$receipt['id']}")->assertOk()->assertJsonCount(0, 'data.receipts');
    }

    public function test_asking_to_borrow_waits_for_a_manager(): void
    {
        $id = $this->item();
        Sanctum::actingAs($this->deacon);
        $this->postJson("/api/equipment/{$id}/loans", ['to_name' => 'Me', 'quantity' => 1])->assertForbidden();
        $this->postJson("/api/equipment/{$id}/loans", ['ask' => true, 'quantity' => 1, 'due_on' => now()->addDays(3)->toDateString()])->assertStatus(422);
        $ask = $this->postJson("/api/equipment/{$id}/loans", ['ask' => true, 'quantity' => 2, 'due_on' => now()->addDays(3)->toDateString(), 'note' => 'Youth camp'])
            ->assertCreated()->json('data.loans.0');
        $this->assertSame('requested', $ask['status']);
        $this->assertTrue($ask['can_cancel']);
        $this->assertFalse($ask['can_decide']);
        $this->postJson("/api/equipment/{$id}/loans", ['ask' => true, 'due_on' => now()->addDays(3)->toDateString(), 'note' => 'Again'])->assertStatus(422);
        $this->postJson("/api/loans/{$ask['id']}/approve")->assertForbidden();
        $this->getJson("/api/equipment/{$id}")->assertJsonPath('data.on_loan', 0);

        // Someone borrows one meanwhile - the ask for two can't be met.
        Sanctum::actingAs($this->senior);
        $this->assertCount(1, $this->getJson('/api/loans?status=requested')->assertOk()->json('data'));
        $this->postJson("/api/equipment/{$id}/loans", ['to_name' => 'Choir', 'quantity' => 1])->assertCreated();
        $this->postJson("/api/loans/{$ask['id']}/approve")->assertStatus(422);
        $back = EquipmentLoan::where('to_name', 'Choir')->value('id');
        $this->postJson("/api/loans/{$back}/return")->assertOk();
        $this->postJson("/api/loans/{$ask['id']}/approve")->assertOk()->assertJsonPath('data.on_loan', 2);
        $this->assertSame('out', EquipmentLoan::find($ask['id'])->status);

        // Declined, and taken back.
        Sanctum::actingAs($this->deacon);
        $two = $this->postJson("/api/equipment/{$id}/loans", ['ask' => true, 'due_on' => now()->addDays(9)->toDateString(), 'note' => 'Bible study'])->json('data.loans.0.id');
        Sanctum::actingAs($this->senior);
        $this->postJson("/api/loans/{$two}/decline", ['reason' => 'Needed on Sunday'])->assertOk();
        $this->assertSame('declined', EquipmentLoan::find($two)->status);
        Sanctum::actingAs($this->deacon);
        $three = $this->postJson("/api/equipment/{$id}/loans", ['ask' => true, 'due_on' => now()->addDays(9)->toDateString(), 'note' => 'Bible study'])->json('data.loans.0.id');
        $this->deleteJson("/api/loans/{$three}")->assertOk();
        $this->assertNull(EquipmentLoan::find($three));

        Sanctum::actingAs($this->otherPastor);
        $this->postJson("/api/equipment/{$id}/loans", ['ask' => true, 'due_on' => now()->addDays(3)->toDateString(), 'note' => 'x'])->assertNotFound();
    }

    public function test_what_we_own_and_the_two_reports(): void
    {
        $guitar = $this->item();
        $this->item(['name' => 'Plastic chairs', 'category' => 'furniture', 'quantity' => 100, 'value' => 800, 'bought_on' => '2025-03-01']);
        $this->item(['name' => 'Old kettle', 'category' => 'kitchen', 'quantity' => 1, 'value' => null]);
        Sanctum::actingAs($this->otherPastor);
        $this->postJson('/api/equipment', ['name' => 'Their generator', 'category' => 'other', 'value' => 90000])->assertCreated();

        Sanctum::actingAs($this->senior);
        $a = $this->getJson('/api/facilities/assets')->assertOk()->json('data');
        $this->assertEquals(110000, $a['worth']);
        $this->assertSame(103, $a['items']);
        $this->assertSame('Furniture', $a['by_kind'][0]['label']);
        $this->assertSame(3, count($a['needs']));
        $this->assertSame(['price', 'receipt', 'photo'], collect($a['needs'])->firstWhere('name', 'Old kettle')['missing']);
        $this->assertSame([2025], array_column($a['by_year'], 'year'));

        $body = ['report_key' => 'facilities.assets', 'format' => 'xlsx', 'territory_id' => $this->myChurch->id];
        Sanctum::actingAs($this->deacon);
        $this->postJson('/api/reports', $body)->assertForbidden();
        Sanctum::actingAs($this->exporter);
        foreach (['facilities.assets', 'facilities.loans'] as $key) {
            $uuid = $this->postJson('/api/reports', ['report_key' => $key] + $body)->assertStatus(202)->json('data.uuid');
            $run = ReportRun::where('uuid', $uuid)->firstOrFail();
            $this->assertSame(ReportRun::STATUS_READY, $run->status, (string) $run->error);
            $this->assertStringStartsWith('MWD-FAC-', $run->verification_code);
        }
        $sections = (new AssetRegisterReport)->build(new ReportContext($this->myChurch, $this->exporter))->sections;
        $this->assertSame(['Instruments', 'Furniture', 'Kitchen'], array_map(fn ($s) => $s->heading, $sections));
        $this->assertSame(['A-0001', 'Acoustic guitar'], array_slice($sections[0]->rows[0], 0, 2));
        $this->assertNotContains('Their generator', collect($sections)->flatMap(fn ($s) => array_column($s->rows, 1))->all());
        $this->assertSame((float) 30000, $sections[0]->rows[0][5]);
        $this->assertNotNull($guitar);
    }
}
