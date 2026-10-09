<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Who, in a stage: a resolver (role here, role above, a permission, named people) and its settings. */
class ApprovalStep extends Model
{
    protected $fillable = ['stage_id', 'resolver_type', 'resolver_config'];

    protected $casts = ['resolver_config' => 'array'];
}
