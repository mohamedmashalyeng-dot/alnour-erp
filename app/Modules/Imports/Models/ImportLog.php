<?php

namespace App\Modules\Imports\Models;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One import job. Status flow:
 *   mapping → validating → validated → processing → completed   (or cancelled / failed)
 */
class ImportLog extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'column_mapping' => 'array', 'options' => 'array', 'headers' => 'array',
            'started_at' => 'datetime', 'completed_at' => 'datetime',
        ];
    }

    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['validating', 'processing'], true);
    }
}
