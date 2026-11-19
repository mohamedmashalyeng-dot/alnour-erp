<?php

namespace App\Modules\Optical\Models;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Core\Models\Attachment;
use App\Modules\Core\Support\DateOnly;
use App\Modules\Customers\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Optical prescription (medical record). Never edited after creation: corrections are a new prescription,
 * and the old one is voided with a reason. medical_notes is encrypted at rest.
 */
class Prescription extends Model
{
    public const EYE_FIELDS = ['od_sph', 'od_cyl', 'od_axis', 'od_add', 'os_sph', 'os_cyl', 'os_axis', 'os_add'];

    protected $fillable = ['number', 'customer_id', 'branch_id', 'type', 'examined_on', 'expires_on', 'examiner_name',
        'od_sph', 'od_cyl', 'od_axis', 'od_add', 'os_sph', 'os_cyl', 'os_axis', 'os_add', 'pd', 'pd_right', 'pd_left',
        'cl_base_curve', 'cl_diameter', 'medical_notes', 'status', 'void_reason', 'voided_by', 'created_by'];

    protected $hidden = ['medical_notes'];

    protected function casts(): array
    {
        $ints = array_fill_keys(array_merge(self::EYE_FIELDS, ['pd', 'pd_right', 'pd_left', 'cl_base_curve', 'cl_diameter']), 'integer');

        return $ints + ['examined_on' => DateOnly::class, 'expires_on' => DateOnly::class, 'medical_notes' => 'encrypted'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function isUsable(): bool
    {
        return $this->status === 'active' && $this->expires_on->toDateString() >= today()->toDateString();
    }
}
