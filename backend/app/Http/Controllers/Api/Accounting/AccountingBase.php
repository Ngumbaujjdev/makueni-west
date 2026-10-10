<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Territory;
use App\Support\AccountingAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What every Accounting controller shares (docs/specs/accounting-spec.md):
 * the place whose books a request is for - the acting place, or one below
 * it to read (?territory_id=) - and the replies. One set of controllers for
 * every level.
 */
abstract class AccountingBase extends Controller
{
    /** The place, when the user may read its books; writing needs $ability there too. */
    protected function place(Request $request, ?string $ability = null): Territory|JsonResponse
    {
        $id = $request->input('territory_id', $request->query('territory_id'));
        $place = AccountingAccess::place($request->user(), $id !== null && ctype_digit((string) $id) ? (int) $id : null);
        if (! $place) {
            return $this->forbidden('These books aren\'t yours to see.');
        }
        if ($ability && ! AccountingAccess::can($request->user(), $place, $ability)) {
            return $this->forbidden(match ($ability) {
                'receipt' => 'Your role can\'t write receipts here.',
                'prepare' => 'Your role can\'t prepare payment vouchers here.',
                'authorise' => 'Your role can\'t authorise payments here.',
                'pay' => 'Your role can\'t pay vouchers here.',
                'journal' => 'Your role can\'t post journals here.',
                'accounts' => 'Your role can\'t add or change accounts here.',
                'chart' => 'Only the diocese finance officer changes the chart of accounts.',
                default => 'Your role can\'t do that here.',
            });
        }

        return $place;
    }

    protected function placeInfo(Territory $place): array
    {
        return ['id' => $place->id, 'name' => $place->name, 'level' => $place->territory_type->value, 'code' => $place->code];
    }

    protected function ok(mixed $data, string $message = 'OK', int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'status' => $status, 'message' => $message, 'data' => $data], $status);
    }

    protected function forbidden(string $message): JsonResponse
    {
        return response()->json(['success' => false, 'status' => 403, 'message' => $message], 403);
    }

    protected function notFound(string $message): JsonResponse
    {
        return response()->json(['success' => false, 'status' => 404, 'message' => $message], 404);
    }

    /** Validation rules for document lines, shared by receipts and vouchers. */
    protected function lineRules(string $prefix = 'lines'): array
    {
        return [
            $prefix => ['required', 'array', 'min:1', 'max:20'],
            "{$prefix}.*.account_id" => ['required', 'integer'],
            "{$prefix}.*.amount" => ['required', 'numeric', 'min:0.01', 'max:999999999999'],
            "{$prefix}.*.fund_id" => ['nullable', 'integer'],
            "{$prefix}.*.budget_line_id" => ['nullable', 'integer'],
            "{$prefix}.*.memo" => ['nullable', 'string', 'max:255'],
            "{$prefix}.*.description" => ['nullable', 'string', 'max:255'],
        ];
    }

    protected const METHOD_RULE = ['nullable', 'in:cash,mpesa,bank,cheque,airtel'];
}
