<?php

namespace Tests\Feature\Accounting;

use App\Approval\Services\WorkflowBuilder;
use App\Models\PaymentVoucher;
use App\Models\StaffAdvance;
use App\Models\User;
use App\Services\Accounting\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Requisitions and advances (docs/specs/accounting-spec.md, A4): ask,
 * approve, pay - the voucher already authorised - an advance accounted for
 * with receipts and change, and an overdue one stopping the next.
 */
class RequisitionsTest extends TestCase
{
    use BuildsBooks, RefreshDatabase;

    private User $youth;

    private User $overseerR;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBooks();
        Notification::fake();
        $this->youth = $this->userWithRole('youth', 'Youth Leader', 'church', $this->myChurch->id, $this->perms('church', ['request', 'approvals']));
        $this->overseerR = $this->userWithRole('regover', 'Regional Overseer', 'region', $this->region->id, $this->perms('region', ['read', 'below', 'approvals']));
        $this->receive(100000);
    }

    private function pastorRule(): void
    {
        $dfo = $this->userWithRole('dfo', 'Diocese Finance Officer', 'diocese', $this->diocese->id, []);
        app(WorkflowBuilder::class)->save(['name' => 'Church', 'subject_type' => '*', 'level' => 'church', 'stages' => [[
            'name' => 'Pastor', 'type' => 'single', 'on_empty' => 'escalate', 'escalate_to' => ['resolver_type' => 'role_above', 'resolver_config' => ['roles' => ['Regional Overseer'], 'level' => 'region']],
            'steps' => [['resolver_type' => 'role_here', 'resolver_config' => ['roles' => ['Senior Pastor']]]],
        ]]], null, $dfo);
    }

    private function ask(array $over = [], ?User $as = null): array
    {
        Sanctum::actingAs($as ?? $this->youth);

        return $this->postJson('/api/accounting/requisitions', array_replace(['kind' => 'payment', 'purpose' => 'Youth camp food', 'amount' => 12000, 'account_id' => $this->acc('5110')->id, 'payee_name' => 'Mama Mboga'], $over))->assertCreated()->json('data');
    }

    public function test_ask_approve_and_pay_with_a_voucher_already_authorised(): void
    {
        $this->pastorRule();
        $r = $this->ask();
        $this->assertSame('submitted', $r['status']);
        $this->assertSame(['Test Senior'], $r['waiting_on']);
        Sanctum::actingAs($this->treasurer);
        $this->postJson("/api/accounting/requisitions/{$r['id']}/pay", ['pay_from_account_id' => $this->cash()->id])->assertStatus(422);

        Sanctum::actingAs($this->authoriser);
        $this->postJson("/api/accounting/requisitions/{$r['id']}/approve")->assertOk()->assertJsonPath('data.status', 'approved');

        Sanctum::actingAs($this->treasurer);
        $paid = $this->postJson("/api/accounting/requisitions/{$r['id']}/pay", ['pay_from_account_id' => $this->cash()->id])->assertCreated()->json('data');
        $pv = PaymentVoucher::find($paid['voucher_id']);
        $this->assertSame('authorised', $pv->status, 'nobody approves the same money twice');
        $this->assertSame($this->authoriser->id, $pv->authorised_by);
        $this->postJson("/api/accounting/requisitions/{$r['id']}/pay", ['pay_from_account_id' => $this->cash()->id])->assertStatus(422);
        $this->postJson("/api/accounting/payment-vouchers/{$pv->id}/pay", ['paid_on' => now()->toDateString()])->assertOk();
        $this->getJson("/api/accounting/requisitions/{$r['id']}")->assertJsonPath('data.status', 'paid');
        $this->assertEquals(12000, app(Ledger::class)->balance($this->myChurch, $this->acc('5110')));
    }

    public function test_with_no_rule_anyone_who_authorises_approves_but_never_their_own(): void
    {
        $r = $this->ask([], $this->treasurer);
        Sanctum::actingAs($this->treasurer);
        $this->getJson("/api/accounting/requisitions/{$r['id']}")->assertJsonPath('data.can.decide', false);
        $this->postJson("/api/accounting/requisitions/{$r['id']}/approve")->assertStatus(422);
        Sanctum::actingAs($this->authoriser);
        $this->postJson("/api/accounting/requisitions/{$r['id']}/reject")->assertStatus(422);
        $this->postJson("/api/accounting/requisitions/{$r['id']}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
    }

    public function test_an_advance_is_accounted_for_with_receipts_and_change_and_an_overdue_one_stops_the_next(): void
    {
        $this->pastorRule();
        $r = $this->ask(['kind' => 'advance', 'purpose' => 'Youth retreat transport', 'amount' => 5000, 'account_id' => null]);
        Sanctum::actingAs($this->authoriser);
        $this->postJson("/api/accounting/requisitions/{$r['id']}/approve")->assertOk();
        Sanctum::actingAs($this->treasurer);
        $pvId = $this->postJson("/api/accounting/requisitions/{$r['id']}/pay", ['pay_from_account_id' => $this->cash()->id])->json('data.voucher_id');
        $this->postJson("/api/accounting/payment-vouchers/{$pvId}/pay", ['paid_on' => now()->toDateString()])->assertOk();
        $ledger = app(Ledger::class);
        $this->assertEquals(5000, $ledger->balance($this->myChurch, $this->chart->account('staff_advances')));
        $adv = StaffAdvance::firstOrFail();
        $this->assertSame($this->youth->id, $adv->user_id);

        $this->postJson("/api/accounting/advances/{$adv->id}/retire", ['date' => now()->toDateString(), 'lines' => [['account_id' => $this->acc('5530')->id, 'amount' => 5200]]])->assertStatus(422);
        $this->postJson("/api/accounting/advances/{$adv->id}/retire", ['date' => now()->toDateString(), 'lines' => [['account_id' => $this->acc('5530')->id, 'amount' => 3000]]])->assertOk()->assertJsonPath('data.outstanding', 2000);

        // Past its date with KES 2,000 still out: no new advance for them.
        $adv->update(['due_on' => now()->subDay()]);
        Sanctum::actingAs($this->youth);
        $this->postJson('/api/accounting/requisitions', ['kind' => 'advance', 'purpose' => 'More', 'amount' => 1000])->assertStatus(422)->assertJsonValidationErrors('kind');
        $this->postJson('/api/accounting/requisitions', ['kind' => 'payment', 'purpose' => 'Bus', 'amount' => 1000, 'account_id' => $this->acc('5530')->id])->assertCreated();

        Sanctum::actingAs($this->treasurer);
        $this->postJson("/api/accounting/advances/{$adv->id}/retire", ['date' => now()->toDateString(), 'returned' => 2000])->assertOk()->assertJsonPath('data.status', 'retired');
        $this->assertEquals(0, $ledger->balance($this->myChurch, $this->chart->account('staff_advances')));
        $this->assertEquals(3000, $ledger->balance($this->myChurch, $this->acc('5530')));
    }

    public function test_the_overseer_above_sees_a_church_requisition_through_approvals_and_its_papers(): void
    {
        Storage::fake('local');
        $this->pastorRule();
        // The pastor asks: it goes up to the overseer.
        $this->authoriser->roles()->first()->givePermissionTo($this->perms('church', ['request']));
        $r = $this->ask([], $this->authoriser);
        $this->post("/api/accounting/requisitions/{$r['id']}/attachments", ['file' => UploadedFile::fake()->createWithContent('quote.pdf', "%PDF-1.4\n%%EOF\n")], ['Accept' => 'application/json'])->assertCreated();

        Sanctum::actingAs($this->overseerR);
        $item = $this->getJson('/api/approvals?tab=waiting')->assertOk()->json('data.items.0');
        $this->assertSame($r['number'], $item['subject']['number']);
        $full = $this->getJson("/api/approvals/requests/{$item['id']}")->assertOk()->assertJsonPath('data.can.decide', true)->json('data');
        $this->assertSame('Youth camp food', collect($full['facts'])->firstWhere(0, 'What for')[1]);
        $this->get("/api/approvals/requests/{$item['id']}/files/{$full['files'][0]['id']}")->assertOk();
        $this->postJson("/api/approvals/requests/{$item['id']}/return", [])->assertStatus(422);
        $this->postJson("/api/approvals/requests/{$item['id']}/approve", ['comment' => 'OK'])->assertOk()->assertJsonPath('data.status', 'approved');

        // A youth leader sees their own requisitions, not the books or anyone else's.
        Sanctum::actingAs($this->youth);
        $this->getJson('/api/accounting/requisitions')->assertOk()->assertJsonPath('data.all', false)->assertJsonCount(0, 'data.items');
        $this->getJson("/api/accounting/requisitions/{$r['id']}")->assertNotFound();
        $this->getJson('/api/accounting/overview')->assertForbidden();
    }
}
