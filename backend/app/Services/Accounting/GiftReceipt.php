<?php

namespace App\Services\Accounting;

use App\Models\Gift;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\Territory;
use App\Reports\ReportColumn;
use App\Reports\ReportData;
use App\Reports\ReportSection;
use App\Services\Pdf\DioceseReportPdf;

/**
 * The receipt a giver keeps for an online gift (docs/specs/accounting-spec.md,
 * A10 - receipt): on the diocese letterhead, from the same engine as every
 * report. The giver has no login, so it is built straight away rather than
 * through the reports queue; the gift's reference is what opens it, the key
 * only the giver is given (the thanks page, the SMS, the email).
 */
final class GiftReceipt
{
    /** What the thanks page shows: the receipt in plain data. */
    public function summary(Gift $g): array
    {
        $journal = Journal::find($g->journal_id);

        return [
            'receipt' => $journal?->number,
            'paid_at' => $g->paid_at?->setTimezone('Africa/Nairobi')->toIso8601String(),
            'mpesa_code' => $this->mpesaCode($g),
            'giver' => trim((string) explode(' ', trim((string) $g->giver_name))[0]) ?: null,
            'phone' => $this->maskPhone((string) $g->giver_phone),
            'lines' => $this->lines($g, $journal),
        ];
    }

    /** The M-Pesa receipt code: ours for a paybill prompt, Paystack's receipt_number for M-Pesa through Paystack. */
    private function mpesaCode(Gift $g): ?string
    {
        if ($this->paidWith($g) !== 'mpesa') {
            return null;
        }
        if ($g->method === 'mpesa') {
            return $g->provider_ref && $g->provider_ref !== $g->reference ? $g->provider_ref : null;
        }
        $code = $g->raw['receipt_number'] ?? ($g->raw['data']['receipt_number'] ?? null);

        return $code ? (string) $code : null;
    }

    /** How a gift was paid, in the giver's words. */
    public const PAID_WITH = ['mpesa' => 'M-Pesa', 'airtel' => 'Airtel Money', 'card' => 'Card', 'bank' => 'Pesalink (bank)'];

    /**
     * mpesa, airtel, card or bank: a paybill prompt is M-Pesa; through Paystack
     * it is read from Paystack's verify (its channel, and the mobile-money
     * provider it names as the "bank").
     */
    public function paidWith(Gift $g): string
    {
        if ($g->method === 'mpesa') {
            return 'mpesa';
        }
        $raw = (array) ($g->raw ?? []);
        $channel = (string) ($raw['channel'] ?? ($raw['authorization']['channel'] ?? $g->result ?? ''));
        $provider = strtolower(($raw['authorization']['bank'] ?? '').' '.($raw['authorization']['brand'] ?? ''));
        if ($channel === 'mobile_money' || $g->result === 'mobile_money') {
            return str_contains($provider, 'airtel') ? 'airtel' : 'mpesa';
        }
        if (in_array($channel, ['bank_transfer', 'bank', 'pesalink', 'eft'], true) || str_contains($provider, 'pesalink')) {
            return 'bank';
        }

        return 'card';
    }

    public function paidWithLabel(Gift $g): string
    {
        return self::PAID_WITH[$this->paidWith($g)];
    }

    /** The PDF, as a string. */
    public function pdf(Gift $g): string
    {
        $place = Territory::find($g->territory_id);
        $s = $this->summary($g);
        $purpose = Paybill::PURPOSES[$g->purpose][0] ?? 'Gift';
        $when = $g->paid_at?->setTimezone('Africa/Nairobi');
        $how = $this->paidWithLabel($g).($g->method === 'paystack' ? ' (Paystack)' : '');
        $data = new ReportData(
            kicker: 'Official receipt',
            title: 'Receipt '.($s['receipt'] ?? $g->reference),
            periodLabel: $when ? $when->format('j M Y, H:i') : '',
            scopeLabel: $place?->name ?? '',
            tiles: [
                ['label' => 'Amount received', 'value' => 'KES '.number_format((float) $g->amount, 2), 'tone' => 'success'],
                ['label' => 'For', 'value' => $purpose, 'tone' => 'primary'],
                ['label' => 'Paid by', 'value' => $how, 'tone' => 'primary'],
            ],
            meta: array_filter([
                'Received from' => trim((string) $g->giver_name) ?: 'A giver',
                'Phone' => $s['phone'],
                'Receipt' => $s['receipt'],
                'Our reference' => $g->reference,
                'M-Pesa code' => $s['mpesa_code'],
                'Date' => $when?->format('j F Y, H:i'),
            ]),
            sections: [new ReportSection('Received for', [ReportColumn::text('For', true), ReportColumn::text('Fund'), ReportColumn::money('KES')], array_map(fn ($l) => [$l['for'], $l['fund'], $l['amount']], $s['lines']), 'Given online through the diocese giving page. Thank you - God bless you.')],
        );

        return (new DioceseReportPdf($data, '', '', $place?->name ?? 'Giving'))->build()->toPdfString();
    }

    /** What the gift was for, as the books received it - one line per income line, or the purpose. */
    private function lines(Gift $g, ?Journal $journal): array
    {
        $rows = $journal
            ? JournalLine::with(['account:id,name,type', 'fund:id,name'])->where('journal_id', $journal->id)->where('credit', '>', 0)->get()
                ->filter(fn ($l) => $l->account?->type === 'income')
                ->map(fn ($l) => ['for' => $l->account->name, 'fund' => $l->fund?->name ?? '', 'amount' => round((float) $l->credit, 2)])->values()->all()
            : [];

        return $rows ?: [['for' => Paybill::PURPOSES[$g->purpose][0] ?? 'Gift', 'fund' => '', 'amount' => round((float) $g->amount, 2)]];
    }

    /** 0712 345 678 -> 07•• ••• 678 (the giver knows it's theirs; nobody else learns it). */
    private function maskPhone(string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', $phone);
        if (strlen($digits) < 9) {
            return null;
        }
        $local = '0'.substr($digits, -9);

        return substr($local, 0, 2).'•• ••• '.substr($local, -3);
    }
}
