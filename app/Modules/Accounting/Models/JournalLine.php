<?php

namespace App\Modules\Accounting\Models;

use App\Modules\Branches\Models\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class JournalLine extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['debit' => 'integer', 'credit' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(function (JournalLine $l) {
            if ($l->entry()->value('status') !== 'draft') {
                throw new LogicException('Posted journal lines are immutable.');
            }
        });
        static::deleting(function (JournalLine $l) {
            if ($l->entry()->value('status') !== 'draft') {
                throw new LogicException('Posted journal lines are immutable.');
            }
        });
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
