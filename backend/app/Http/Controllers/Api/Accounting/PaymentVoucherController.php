<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Models\PaymentVoucher;
use App\Models\Territory;
use App\Services\Accounting\Books;
use App\Services\Accounting\PaymentVouchers;
use App\Support\AccountingAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Payment vouchers (docs/specs/accounting-spec.md): prepared, authorised by
 * someone other than who prepared it, then paid - which posts it.
 */
class PaymentVoucherController extends AccountingBase
{
    public function __construct(private PaymentVouchers $vouchers, private Books $books) {}

    /** GET /accounting/payment-vouchers?status= */
    public function index(Request $request): JsonResponse
    {
        $place = $this->place($request);
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $status = $request->query('status');
        $items = PaymentVoucher::with(['lines.account:id,name', 'payFrom', 'preparer', 'authoriser', 'rejecter', 'payer', 'journal:id,number', 'media'])
            ->where('territory_id', $place->id)
            ->when(in_array($status, array_keys(PaymentVoucher::STATUSES), true), fn ($q) => $q->where('status', $status))
            ->orderByDesc('date')->orderByDesc('id')->limit(1000)->get();

        return $this->ok([
            'place' => $this->placeInfo($place),
            'can' => AccountingAccess::abilities($request->user(), $place),
            'me' => $request->user()->id,
            'items' => $items->map(fn ($pv) => $this->books->presentVoucher($pv))->values(),
        ]);
    }

    /** GET /accounting/payment-vouchers/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        [$pv, $place, $deny] = $this->voucher($request, $id);

        return $deny ?? $this->ok($this->present($request, $pv, $place));
    }

    /** POST /accounting/payment-vouchers */
    public function store(Request $request): JsonResponse
    {
        $place = $this->place($request, 'prepare');
        if ($place instanceof JsonResponse) {
            return $place;
        }
        $pv = $this->vouchers->prepare($place, $request->user(), $this->validated($request));

        return $this->ok($this->present($request, $pv, $place), "Voucher {$pv->number} prepared - it now needs authorising.", 201);
    }

    /** PUT /accounting/payment-vouchers/{id} */
    public function update(Request $request, int $id): JsonResponse
    {
        [$pv, $place, $deny] = $this->voucher($request, $id, 'prepare');
        if ($deny) {
            return $deny;
        }
        $pv = $this->vouchers->update($pv, $request->user(), $this->validated($request));

        return $this->ok($this->present($request, $pv, $place), 'Saved - it waits to be authorised again.');
    }

    /** POST /accounting/payment-vouchers/{id}/authorise {note?} */
    public function authorise(Request $request, int $id): JsonResponse
    {
        [$pv, $place, $deny] = $this->voucher($request, $id, 'authorise');
        if ($deny) {
            return $deny;
        }
        $data = $request->validate(['note' => ['nullable', 'string', 'max:255']]);
        $pv = $this->vouchers->authorise($pv, $request->user(), $data['note'] ?? null);

        return $this->ok($this->present($request, $pv, $place), "{$pv->number} authorised - it can be paid.");
    }

    /** POST /accounting/payment-vouchers/{id}/reject {reason} */
    public function reject(Request $request, int $id): JsonResponse
    {
        [$pv, $place, $deny] = $this->voucher($request, $id, 'authorise');
        if ($deny) {
            return $deny;
        }
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], ['reason.required' => 'Say what needs fixing.']);
        $pv = $this->vouchers->reject($pv, $request->user(), $data['reason']);

        return $this->ok($this->present($request, $pv, $place), "{$pv->number} sent back.");
    }

    /** POST /accounting/payment-vouchers/{id}/pay {paid_on, method?, reference?} */
    public function pay(Request $request, int $id): JsonResponse
    {
        [$pv, $place, $deny] = $this->voucher($request, $id, 'pay');
        if ($deny) {
            return $deny;
        }
        $data = $request->validate(['paid_on' => ['required', 'date'], 'method' => self::METHOD_RULE, 'reference' => ['nullable', 'string', 'max:100']]);
        if (in_array($data['method'] ?? null, ['mpesa', 'cheque', 'bank'], true) && trim((string) ($data['reference'] ?? '')) === '') {
            throw ValidationException::withMessages(['reference' => [($data['method'] === 'mpesa' ? 'Enter the M-Pesa code.' : ($data['method'] === 'cheque' ? 'Enter the cheque number.' : 'Enter the bank reference.'))]]);
        }
        $pv = $this->vouchers->pay($pv, $request->user(), $data);

        return $this->ok($this->present($request, $pv, $place), "{$pv->number} paid and posted to the books.");
    }

    /** POST /accounting/payment-vouchers/{id}/reverse {reason} - undo the payment (whoever keeps the books). */
    public function reversePayment(Request $request, int $id): JsonResponse
    {
        [$pv, $place, $deny] = $this->voucher($request, $id, 'journal');
        if ($deny) {
            return $deny;
        }
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], ['reason.required' => 'Say why the payment is being reversed.']);
        $pv = $this->vouchers->reversePayment($pv, $request->user(), $data['reason']);

        return $this->ok($this->present($request, $pv, $place), "The payment on {$pv->number} was reversed.");
    }

    /** POST /accounting/payment-vouchers/{id}/cancel */
    public function cancel(Request $request, int $id): JsonResponse
    {
        [$pv, $place, $deny] = $this->voucher($request, $id, 'prepare');
        if ($deny) {
            return $deny;
        }
        $pv = $this->vouchers->cancel($pv, $request->user());

        return $this->ok($this->present($request, $pv, $place), "{$pv->number} cancelled.");
    }

    /** POST /accounting/payment-vouchers/{id}/attachments {file} - the invoice, quote or the payee's receipt. */
    public function addAttachment(Request $request, int $id): JsonResponse
    {
        [$pv, $place, $deny] = $this->voucher($request, $id);
        if ($deny) {
            return $deny;
        }
        if (! $this->mayWrite($request, $place)) {
            return $this->forbidden('Your role can\'t add files here.');
        }
        $request->validate(['file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120']], ['file.max' => 'The file must be 5 MB or smaller.', 'file.mimes' => 'Attach a photo (JPG, PNG, WebP) or a PDF.']);
        if ($pv->getMedia('attachments')->count() >= PaymentVoucher::MAX_ATTACHMENTS) {
            throw ValidationException::withMessages(['file' => ['A voucher can hold '.PaymentVoucher::MAX_ATTACHMENTS.' files.']]);
        }
        $file = $request->file('file');
        $pv->addMedia($file)->usingFileName(Str::uuid().'.'.strtolower($file->getClientOriginalExtension() ?: 'bin'))
            ->usingName(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) ?: 'Invoice')
            ->withCustomProperties(['added_by' => $request->user()->id])->toMediaCollection('attachments');

        return $this->ok($this->present($request, $pv->fresh(), $place), 'Attached.', 201);
    }

    /** GET /accounting/payment-vouchers/{id}/attachments/{media} */
    public function showAttachment(Request $request, int $id, int $media): Response|JsonResponse
    {
        [$pv, , $deny] = $this->voucher($request, $id);
        if ($deny) {
            return $deny;
        }
        $file = $pv->getMedia('attachments')->firstWhere('id', $media);
        if (! $file) {
            return $this->notFound('That file isn\'t on this voucher.');
        }

        return response()->file($file->getPath(), ['Content-Type' => $file->mime_type, 'Content-Disposition' => 'inline; filename="'.addslashes($file->file_name).'"']);
    }

    /** DELETE /accounting/payment-vouchers/{id}/attachments/{media} */
    public function removeAttachment(Request $request, int $id, int $media): JsonResponse
    {
        [$pv, $place, $deny] = $this->voucher($request, $id);
        if ($deny) {
            return $deny;
        }
        if (! $this->mayWrite($request, $place) || $pv->status === 'paid') {
            return $this->forbidden($pv->status === 'paid' ? 'A paid voucher keeps its papers.' : 'Your role can\'t remove files here.');
        }
        $file = $pv->getMedia('attachments')->firstWhere('id', $media);
        if (! $file) {
            return $this->notFound('That file isn\'t on this voucher.');
        }
        $file->delete();

        return $this->ok($this->present($request, $pv->fresh(), $place), 'Removed.');
    }

    // ------------------------------------------------------------------ helpers

    /** @return array{0: ?PaymentVoucher, 1: ?Territory, 2: ?JsonResponse} */
    private function voucher(Request $request, int $id, ?string $ability = null): array
    {
        $pv = PaymentVoucher::find($id);
        $place = $pv ? Territory::find($pv->territory_id) : null;
        if (! $pv || ! $place || ! AccountingAccess::canRead($request->user(), $place)) {
            return [null, null, $this->notFound('That voucher isn\'t in the books.')];
        }
        if ($ability && ! AccountingAccess::can($request->user(), $place, $ability)) {
            return [null, null, $this->forbidden(match ($ability) {
                'authorise' => 'Your role can\'t authorise payments here.',
                'pay' => 'Your role can\'t pay vouchers here.',
                'journal' => 'Only whoever keeps the books can reverse a payment.',
                default => 'Your role can\'t change vouchers here.',
            })];
        }

        return [$pv, $place, null];
    }

    private function present(Request $request, PaymentVoucher $pv, Territory $place): array
    {
        $pv->loadMissing(['lines.account', 'payFrom', 'preparer', 'authoriser', 'rejecter', 'payer', 'journal']);
        $can = AccountingAccess::abilities($request->user(), $place);
        $me = $request->user()->id;

        return $this->books->presentVoucher($pv, true) + [
            'place' => $this->placeInfo($place),
            'can' => $can + [
                'edit' => $can['prepare'] && in_array($pv->status, ['prepared', 'rejected'], true),
                'authorise_this' => $can['authorise'] && $pv->status === 'prepared' && (int) $pv->prepared_by !== $me,
                'reject_this' => $can['authorise'] && in_array($pv->status, ['prepared', 'authorised'], true),
                'pay_this' => $can['pay'] && $pv->status === 'authorised',
                'cancel_this' => $can['prepare'] && in_array($pv->status, ['prepared', 'authorised', 'rejected'], true),
                'reverse_this' => $can['journal'] && $pv->status === 'paid',
                'own_voucher' => (int) $pv->prepared_by === $me,
            ],
        ];
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'date' => ['required', 'date'],
            'payee_name' => ['required', 'string', 'max:150'],
            'payee_phone' => ['nullable', 'string', 'max:30'],
            'pay_from_account_id' => ['required', 'integer'],
            'narration' => ['required', 'string', 'max:255'],
        ] + $this->lineRules(), ['payee_name.required' => 'Who is being paid?', 'narration.required' => 'Say what the payment is for.', 'lines.required' => 'Add what is being paid for.']);
    }

    private function mayWrite(Request $request, Territory $place): bool
    {
        foreach (['prepare', 'pay', 'journal'] as $ability) {
            if (AccountingAccess::can($request->user(), $place, $ability)) {
                return true;
            }
        }

        return false;
    }
}
