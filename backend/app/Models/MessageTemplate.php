<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A saved message of a place, to use again. */
class MessageTemplate extends Model
{
    protected $fillable = ['territory_id', 'name', 'channel', 'subject', 'body', 'created_by'];
}
