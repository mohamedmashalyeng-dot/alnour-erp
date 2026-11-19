<?php

namespace App\Modules\Expenses\Models;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Core\Models\Attachment;
use App\Modules\Core\Support\DateOnly;
use App\Modules\Treasury\Models\BankAccount;
use App\Modules\Treasury\Models\CashRegister;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;

class Expense extends Model
{
    protected $fillable = ['uuid', 'branch_id', 'expense_category_id', 'expense_date', 'description', 'amount', 'tax_amount', 'total',
        'payment_source', 'cash_register_id', 'bank_account_id', 'reference'];

    protected function casts(): array
    {
        return ['expense_date' => DateOnly::class, 'approved_at' => 'datetime', 'amount' => 'integer', 'tax_amount' => 'integer', 'total' => 'integer'];
    }

    protected static function booted(): void
    {
        // Approved expenses are posted to the ledger and frozen.
        static::updating(function (Expense $e) {
            if ($e->getOriginal('status') === 'approved') {
                throw new LogicException('Approved expenses cannot be modified.');
            }
        });
        static::deleting(function (Expense $e) {
            if ($e->status === 'approved') {
                throw new LogicException('Approved expenses cannot be deleted.');
            }
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function cashRegister(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
