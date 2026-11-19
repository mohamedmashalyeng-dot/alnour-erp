<?php

namespace App\Modules\Branches\Models;

use App\Models\User;
use App\Modules\Inventory\Models\Inventory;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[UseFactory(BranchFactory::class)]
class Branch extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name', 'name_en', 'phone', 'email', 'address', 'city', 'tax_number', 'is_active', 'notes'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function inventory(): HasMany
    {
        return $this->hasMany(Inventory::class);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function displayName(): string
    {
        return app()->getLocale() === 'en' && $this->name_en ? $this->name_en : $this->name;
    }
}
