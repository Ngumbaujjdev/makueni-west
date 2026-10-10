<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One month a remittance covers (A6), with what was due that month for a share. */
class RemittanceLine extends Model
{
    protected $fillable = ['remittance_id', 'month', 'amount', 'due'];

    protected $casts = ['amount' => 'decimal:2', 'due' => 'decimal:2'];
}
