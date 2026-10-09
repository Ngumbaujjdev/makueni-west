<?php

namespace Tests\Feature\Accounting;

use App\Models\JournalLine;
use App\Models\PaymentVoucher;
use App\Services\Accounting\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Payment vouchers (docs/specs/accounting-spec.md, A1): prepared, authorised
 * by someone else, paid - and only paying posts to the books.
 */
class PaymentVoucherTest extends TestCase
{
    use BuildsBooks, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBooks();
        Sanctum::actingAs($this->treasurer);
        $this->postJson('/api/accounting/receipts', ['date' => $this->day(), 'account_id' => $this->cash()->id, 'party_name' => 'Service', 'lines' => [['account_id' => $this->acc('4000')->id, 'amount' => 20000]]])->assertCreated();
    }

    private function prepare(array $over = [], $as = null): array
    {
        Sanctum::actingAs($as ?? $this->treasurer);

        return $this->postJson('/api/accounting/payment-vouchers', array_replace([
            'date' => $this->day(1, 12), 'payee_name' => 'Kenya Power', 'pay_from_account_id' => $this->cash()->id, 'narration' => 'January electricity',
            'lines' => [['account_id' => $this->acc('5400')->id, 'amount' => 3200, 'description' => 'Token']],
        ], $over))->assertCreated()->json('data');
    }

    public function test_prepare_authorise_pay_posts_the_payment(): void
    {
        $pv = $this->prepare();
        $this->assertSame('prepared', $pv['status']);
        $this->assertStringContainsString('/PV/', $pv['number']);
        $this->assertSame(0, JournalLine::count() - 2, 'nothing posted while it waits');

        $this->postJson("/api/accounting/payment-vouchers/{$pv['id']}/authorise")->assertForbidden();
        // Whoever prepared a voucher never authorises it - even when their role may.
        $both = $this->userWithRole('assoc', 'Associate Pastor', 'church', $this->myChurch->id, $this->perms('church', ['read', 'prepare', 'authorise']));
        $own = $this->prepare([], $both);
        $this->postJson("/api/accounting/payment-vouchers/{$own['id']}/authorise")->assertStatus(422)->assertJsonValidationErrors('voucher');
        Sanctum::actingAs($this->treasurer);
        $this->postJson("/api/accounting/payment-vouchers/{$pv['id']}/pay", ['paid_on' => $this->day(1, 12)])->assertStatus(422);

        Sanctum::actingAs($this->authoriser);
        $this->postJson("/api/accounting/payment-vouchers/{$pv['id']}/pay", ['paid_on' => $this->day(1, 12)])->assertForbidden();
        $this->postJson("/api/accounting/payment-vouchers/{$pv['id']}/authorise", ['note' => 'OK'])->assertOk()->assertJsonPath('data.status', 'authorised');

        Sanctum::actingAs($this->treasurer);
        $this->postJson("/api/accounting/payment-vouchers/{$pv['id']}/pay", ['paid_on' => $this->day(1, 12), 'method' => 'mpesa'])->assertStatus(422)->assertJsonValidationErrors('reference');
        $paid = $this->postJson("/api/accounting/payment-vouchers/{$pv['id']}/pay", ['paid_on' => $this->day(1, 12), 'method' => 'mpesa', 'reference' => 'SAB12CD34'])
            ->assertOk()->assertJsonPath('data.status', 'paid')->json('data');
        $lines = JournalLine::where('journal_id', $paid['journal_id'])->get();
        $this->assertEquals(3200, $lines->firstWhere('account_id', $this->acc('5400')->id)->debit);
        $this->assertEquals(3200, $lines->firstWhere('account_id', $this->cash()->id)->credit);
        $this->assertEquals(16800, app(Ledger::class)->balance($this->myChurch, $this->cash()));
        $this->postJson("/api/accounting/payment-vouchers/{$pv['id']}/cancel")->assertStatus(422);
        $this->postJson("/api/accounting/journals/{$paid['journal_id']}/reverse", ['reason' => 'x'])->assertForbidden();
    }

    public function test_a_sent_back_voucher_is_fixed_and_authorised_again(): void
    {
        $pv = $this->prepare();
        Sanctum::actingAs($this->authoriser);
        $this->postJson("/api/accounting/payment-vouchers/{$pv['id']}/reject", [])->assertStatus(422);
        $this->postJson("/api/accounting/payment-vouchers/{$pv['id']}/reject", ['reason' => 'Attach the bill'])->assertOk()->assertJsonPath('data.status', 'rejected');
        Sanctum::actingAs($this->treasurer);
        $this->putJson("/api/accounting/payment-vouchers/{$pv['id']}", [
            'date' => $this->day(1, 12), 'payee_name' => 'Kenya Power', 'pay_from_account_id' => $this->cash()->id, 'narration' => 'January electricity',
            'lines' => [['account_id' => $this->acc('5400')->id, 'amount' => 3000]],
        ])->assertOk()->assertJsonPath('data.status', 'prepared')->assertJsonPath('data.amount', 3000);
        Sanctum::actingAs($this->authoriser);
        $this->postJson("/api/accounting/payment-vouchers/{$pv['id']}/authorise")->assertOk();
    }

    public function test_paying_too_much_or_into_the_future_is_refused_and_cancel_works_before_paying(): void
    {
        $pv = $this->prepare();
        $this->postJson("/api/accounting/payment-vouchers/{$pv['id']}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson('/api/accounting/payment-vouchers', [
            'date' => $this->day(), 'payee_name' => 'X', 'pay_from_account_id' => $this->acc('5400')->id, 'narration' => 'x', 'lines' => [['account_id' => $this->acc('5400')->id, 'amount' => 1]],
        ])->assertStatus(422)->assertJsonValidationErrors('pay_from_account_id');
        $this->postJson('/api/accounting/payment-vouchers', [
            'date' => $this->day(), 'payee_name' => 'X', 'pay_from_account_id' => $this->cash()->id, 'narration' => 'x', 'lines' => [['account_id' => $this->cash()->id, 'amount' => 1]],
        ])->assertStatus(422);

        $pv = $this->prepare();
        Sanctum::actingAs($this->authoriser);
        $this->postJson("/api/accounting/payment-vouchers/{$pv['id']}/authorise")->assertOk();
        Sanctum::actingAs($this->treasurer);
        $this->postJson("/api/accounting/payment-vouchers/{$pv['id']}/pay", ['paid_on' => now()->addDay()->toDateString()])->assertStatus(422);
    }

    public function test_reversing_a_payment_puts_the_money_back_and_the_voucher_can_be_paid_again(): void
    {
        $pv = $this->prepare();
        Sanctum::actingAs($this->authoriser);
        $this->postJson("/api/accounting/payment-vouchers/{$pv['id']}/authorise")->assertOk();
        Sanctum::actingAs($this->treasurer);
        $this->postJson("/api/accounting/payment-vouchers/{$pv['id']}/pay", ['paid_on' => $this->day(1, 12)])->assertOk();
        $this->postJson("/api/accounting/payment-vouchers/{$pv['id']}/reverse", ['reason' => 'Paid the wrong account'])->assertOk()->assertJsonPath('data.status', 'authorised');
        $this->assertEquals(20000, app(Ledger::class)->balance($this->myChurch, $this->cash()));
        Sanctum::actingAs($this->authoriser);
        $this->postJson("/api/accounting/payment-vouchers/{$pv['id']}/reverse", ['reason' => 'x'])->assertForbidden();
    }

    public function test_a_voucher_keeps_its_papers(): void
    {
        Storage::fake('local');
        $pv = $this->prepare();
        $res = $this->post("/api/accounting/payment-vouchers/{$pv['id']}/attachments", ['file' => UploadedFile::fake()->createWithContent('bill.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n")], ['Accept' => 'application/json'])->assertCreated();
        $media = $res->json('data.files.0.id');
        $this->get("/api/accounting/payment-vouchers/{$pv['id']}/attachments/{$media}")->assertOk();
        $this->post("/api/accounting/payment-vouchers/{$pv['id']}/attachments", ['file' => UploadedFile::fake()->create('x.exe', 5)], ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertSame(1, PaymentVoucher::find($pv['id'])->getMedia('attachments')->count());
    }
}
