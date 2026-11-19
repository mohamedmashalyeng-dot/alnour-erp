<?php

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Configurable link between a posting role (e.g. "sales.revenue") and an account.
 * Application logic only references mapping keys, never account codes.
 */
class AccountMapping extends Model
{
    protected $fillable = ['key', 'account_id'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
