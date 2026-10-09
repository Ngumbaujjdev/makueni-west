<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The trail of a request: submitted, stage opened, decided, reminded, escalated, blocked, finished. */
class ApprovalEvent extends Model
{
    protected $fillable = ['request_id', 'type', 'payload', 'actor_id'];

    protected $casts = ['payload' => 'array'];
}
