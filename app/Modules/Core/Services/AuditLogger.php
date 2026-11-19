<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class AuditLogger
{
    /** Attributes never written to the audit trail. */
    private const HIDDEN = ['password', 'remember_token', 'two_factor_secret'];

    /** Personal/medical attributes: the change is recorded, the value is not. */
    private const REDACTED = ['national_id', 'address', 'emergency_contact_phone', 'medical_notes',
        'od_sph', 'od_cyl', 'od_axis', 'od_add', 'os_sph', 'os_cyl', 'os_axis', 'os_add', 'pd', 'pd_right', 'pd_left'];

    private function clean(array $values): array
    {
        $values = Arr::except($values, self::HIDDEN);
        foreach (self::REDACTED as $key) {
            if (array_key_exists($key, $values) && $values[$key] !== null) {
                $values[$key] = '[redacted]';
            }
        }

        return $values;
    }

    public function log(string $event, ?Model $subject = null, array $old = [], array $new = [], ?int $branchId = null): AuditLog
    {
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();

        return AuditLog::create([
            'user_id' => auth()->id(),
            'branch_id' => $branchId ?? ($subject?->getAttribute('branch_id')),
            'event' => $event,
            'auditable_type' => $subject?->getMorphClass(),
            'auditable_id' => $subject?->getKey(),
            'old_values' => $old ? $this->clean($old) : null,
            'new_values' => $new ? $this->clean($new) : null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? Str::limit((string) $request->userAgent(), 250, '') : null,
        ]);
    }

    public function created(Model $model, string $prefix): AuditLog
    {
        return $this->log("$prefix.created", $model, [], $model->attributesToArray());
    }

    public function updated(Model $model, string $prefix, array $original): AuditLog
    {
        $changes = Arr::except($model->getChanges(), ['updated_at']);

        return $this->log("$prefix.updated", $model, Arr::only($original, array_keys($changes)), $changes);
    }
}
