<?php

namespace App\Modules\Suppliers\Models;

use App\Modules\Payments\Models\Payment;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Database\Factories\SupplierFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[UseFactory(SupplierFactory::class)]
class Supplier extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name', 'company_name', 'phone', 'email', 'address', 'tax_number', 'notes', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
