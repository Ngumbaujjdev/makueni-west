<?php

namespace Tests\Feature\Accounting;

use App\Jobs\SendGiftReceipt;
use App\Models\Gift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The giver's receipt (docs/specs/accounting-spec.md, A10 - receipt): the
 * thanks page shows it, it downloads as a diocese PDF, and the SMS carries
 * the link back to it - for a paid gift only.
 */
class GiftReceiptTest extends TestCase
{
    use BuildsBooks, RefreshDatabase;

    private function paidGift(array $over = []): Gift
    {
        $this->buildBooks();
        $journal = $this->receive(1500, null, now()->toDateString());

        return Gift::create(array_replace([
            'reference' => 'GFT-261010-ABC123', 'territory_id' => $this->myChurch->id, 'purpose' => 'T', 'amount' => 1500,
            'giver_name' => 'Jane Mutua', 'giver_phone' => '+254712345678', 'method' => 'mpesa', 'provider_ref' => 'TJA1B2C3D4',
            'status' => 'paid', 'journal_id' => $journal, 'paid_at' => now(),
        ], $over));
    }

    public function test_a_paid_gift_shows_its_receipt_and_downloads_it_as_a_pdf(): void
    {
        $gift = $this->paidGift();

        $data = $this->getJson("/api/give/status/{$gift->reference}")->assertOk()->json('data');
        $this->assertSame('Jane', $data['giver'], 'the first name only');
        $this->assertSame('TJA1B2C3D4', $data['mpesa_code']);
        $this->assertSame('07•• ••• 678', $data['phone']);
        $this->assertEquals(1500, collect($data['lines'])->sum('amount'));
        $this->assertStringEndsWith("/api/give/receipt/{$gift->reference}", $data['receipt_url']);

        $pdf = $this->get("/api/give/receipt/{$gift->reference}")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        // Every PDF is set in Times (reports-spec, Outputs).
        $this->assertStringContainsString('/BaseFont /Times-Roman', $pdf->getContent());
        $this->assertStringContainsString('/BaseFont /Times-Bold', $pdf->getContent());
    }

    public function test_an_unpaid_or_unknown_gift_has_no_receipt(): void
    {
        $gift = $this->paidGift(['status' => 'pending']);
        $this->get("/api/give/receipt/{$gift->reference}")->assertNotFound();
        $this->get('/api/give/receipt/GFT-NOPE')->assertNotFound();
        $this->assertArrayNotHasKey('receipt_url', $this->getJson("/api/give/status/{$gift->reference}")->json('data'));
    }

    public function test_the_sms_receipt_carries_the_link_back_to_it(): void
    {
        $gift = $this->paidGift();
        $sms = SendGiftReceipt::sms($gift, $this->myChurch);
        $this->assertStringContainsString('Thank you Jane. '.$this->myChurch->name.' has received your tithe of KES 1,500.00', $sms);
        $this->assertStringEndsWith('Receipt: '.rtrim(config('app.frontend_url'), '/').'/give-thanks?ref='.$gift->reference, $sms);
    }
}
