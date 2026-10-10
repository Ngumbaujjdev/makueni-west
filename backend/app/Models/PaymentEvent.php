<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A callback or webhook as it arrived (docs/specs/accounting-spec.md, A8):
 * from whom, whether its key and address checked out, the payload, and what
 * became of it. Nothing that comes in is lost.
 */
class PaymentEvent extends Model
{
    protected $fillable = ['provider', 'kind', 'key_ok', 'ip', 'payload', 'status', 'error', 'subject_type', 'subject_id'];

    protected $casts = ['key_ok' => 'boolean', 'payload' => 'array'];
}
