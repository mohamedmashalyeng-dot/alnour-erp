<?php

namespace App\Modules\Accounting\Models;

use App\Modules\Accounting\Enums\AccountType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    protected $fillable = ['code', 'name', 'name_en', 'description', 'description_en', 'type', 'normal_balance', 'parent_id', 'is_postable', 'is_active'];

    protected function casts(): array
    {
        return ['type' => AccountType::class, 'is_postable' => 'boolean', 'is_active' => 'boolean', 'is_system' => 'boolean'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('code');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function displayName(): string
    {
        return app()->getLocale() === 'en' && $this->name_en ? $this->name_en : $this->name;
    }

    public function isDebitNormal(): bool
    {
        return $this->normal_balance === 'debit';
    }
}
