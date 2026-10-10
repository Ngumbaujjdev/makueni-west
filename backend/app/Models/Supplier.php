<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Someone a place buys from (docs/specs/accounting-spec.md, A5): their
 * contacts, KRA PIN and how to pay them. Kept per place; switched off rather
 * than removed once used.
 */
class Supplier extends Model implements \OwenIt\Auditing\Contracts\Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $fillable = ['territory_id', 'name', 'phone', 'email', 'kra_pin', 'pay_details', 'payee', 'notes', 'is_active', 'created_by'];

    protected $casts = ['payee' => 'array', 'is_active' => 'boolean'];

    /** Has anything been quoted, ordered or billed from them? */
    public function isUsed(): bool
    {
        return Quotation::where('supplier_id', $this->id)->exists() || PurchaseOrder::where('supplier_id', $this->id)->exists();
    }
}
