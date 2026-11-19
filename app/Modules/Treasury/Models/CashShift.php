<?php

namespace App\Modules\Treasury\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashShift extends Model
{
    protected $fillable = ['cash_register_id', 'user_id', 'opening_float', 'opened_at', 'notes'];

    protected function casts(): array
    {
        return [
            'opening_float' => 'integer', 'expected_cash' => 'integer', 'counted_cash' => 'integer', 'variance' => 'integer',
            'opened_at' => 'datetime', 'closed_at' => 'datetime',
        ];
    }

    public function register(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class, 'cash_register_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
