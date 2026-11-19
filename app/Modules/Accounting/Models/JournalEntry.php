<?php

namespace App\Modules\Accounting\Models;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Core\Support\DateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Journal entry header. Once posted, its lines are immutable; the only allowed change is
 * marking it "reversed" when a reversal entry is posted. Drafts (manual journals) may be edited.
 */
class JournalEntry extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['entry_date' => DateOnly::class, 'posted_at' => 'datetime', 'total' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(function (JournalEntry $e) {
            if ($e->getOriginal('status') === 'draft') {
                return;
            }
            if (array_diff(array_keys($e->getDirty()), ['status', 'reversed_by_id', 'updated_at']) || $e->status !== 'reversed') {
                throw new LogicException('Posted journal entries are immutable. Post a reversal instead.');
            }
        });
        static::deleting(function (JournalEntry $e) {
            if ($e->status !== 'draft') {
                throw new LogicException('Posted journal entries cannot be deleted.');
            }
        });
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_by_id');
    }
}
