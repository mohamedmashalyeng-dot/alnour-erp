<?php

namespace App\Modules\Customers\Models;

use App\Modules\Branches\Models\Branch;
use App\Modules\Payments\Models\Payment;
use App\Modules\Sales\Models\Sale;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[UseFactory(CustomerFactory::class)]
class Customer extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name', 'phone', 'email', 'address', 'notes', 'is_active', 'branch_id', 'created_by'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_walk_in' => 'boolean', 'loyalty_points' => 'integer', 'loyalty_lifetime_points' => 'integer'];
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
