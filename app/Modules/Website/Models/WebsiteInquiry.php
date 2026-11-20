<?php

namespace App\Modules\Website\Models;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Catalog\Models\Product;
use App\Modules\Core\Support\DateOnly;
use App\Modules\Website\Enums\InquiryStatus;
use App\Modules\Website\Enums\InquiryType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A request sent from the public website. Status changes go through InquiryService.
 */
class WebsiteInquiry extends Model
{
    /** Status, handler and IP hash are set by InquiryService only. */
    protected $fillable = ['type', 'name', 'phone', 'message', 'preferred_date', 'product_id', 'branch_id'];

    protected $hidden = ['message', 'ip_hash'];

    protected function casts(): array
    {
        return [
            'type' => InquiryType::class,
            'status' => InquiryStatus::class,
            'message' => 'encrypted',
            'preferred_date' => DateOnly::class,
            'handled_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }
}
