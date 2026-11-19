<?php

namespace App\Modules\Treasury\Models;

use App\Models\User;
use App\Modules\Core\Support\DateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankReconciliation extends Model
{
    protected $fillable = ['bank_account_id', 'statement_date', 'statement_balance', 'user_id'];

    protected function casts(): array
    {
        return ['statement_date' => DateOnly::class, 'statement_balance' => 'integer'];
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
