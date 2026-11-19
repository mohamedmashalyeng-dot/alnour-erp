<?php

namespace App\Modules\HR\Services;

use App\Models\User;
use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Support\Money;
use App\Modules\HR\Models\AttendanceRecord;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\HR\Models\PerformanceReview;
use App\Modules\HR\Models\SalaryChange;
use App\Modules\Payroll\Models\Employee;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * HR: employee profiles, attendance, leave, salary history and performance reviews.
 * Permissions: hr.view (directory), hr.manage (attendance, leave decisions, profiles, reviews),
 * hr.sensitive (national ID, address, emergency phone, salary history), hr.self (own check-in/out and leave).
 * Salary amounts remain under payroll.manage.
 */
class HrService
{
    public function __construct(private BranchAccess $branches, private AuditLogger $audit) {}

    // ---------------------------------------------------------------- profile

    /** Update HR profile fields (never the salary — see changeSalary). Sensitive fields need hr.sensitive. */
    public function updateProfile(Employee $employee, array $data): Employee
    {
        Gate::authorize('hr.manage');
        $this->branches->authorize($employee->branch_id);
        $rules = [
            'employee_code' => ['nullable', 'string', 'max:32', Rule::unique('employees', 'employee_code')->ignore($employee->id)],
            'name' => ['required', 'string', 'max:255'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'employment_type' => ['required', Rule::in(Employee::TYPES)],
            'employment_status' => ['required', Rule::in(Employee::STATUSES)],
            'hired_on' => ['nullable', 'date'],
            'terminated_on' => ['nullable', 'date', 'required_if:employment_status,terminated'],
            'annual_leave_days' => ['required', 'integer', 'min:0', 'max:60'],
            'shift_start' => ['nullable', 'date_format:H:i'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
        if (Gate::allows('hr.sensitive')) {
            $rules += [
                'national_id' => ['nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9\-]+$/'],
                'address' => ['nullable', 'string', 'max:500'],
                'emergency_contact_phone' => ['nullable', 'string', 'max:32'],
            ];
        }
        // Only validated keys are kept, so sensitive fields sent without hr.sensitive are ignored.
        $data = Validator::make($data, $rules)->validate();
        $data['is_active'] = $data['employment_status'] !== 'terminated';
        if ($data['employment_status'] !== 'terminated') {
            $data['terminated_on'] = null;
        }

        $original = $employee->getAttributes();
        $employee->fill($data)->save();
        $this->audit->updated($employee, 'hr.employee', $original);

        return $employee;
    }

    /** Change the base salary with an effective date and reason; history is immutable. */
    public function changeSalary(Employee $employee, int $newSalary, string $effectiveOn, ?string $reason): SalaryChange
    {
        Gate::authorize('payroll.manage');
        $this->branches->authorize($employee->branch_id);
        if ($newSalary < 0) {
            throw ValidationException::withMessages(['new_salary' => __('Invalid amount.')]);
        }
        if (! strtotime($effectiveOn)) {
            throw ValidationException::withMessages(['effective_on' => __('Invalid date.')]);
        }
        if ($newSalary === $employee->base_salary) {
            throw ValidationException::withMessages(['new_salary' => __('The new salary equals the current salary.')]);
        }

        return DB::transaction(function () use ($employee, $newSalary, $effectiveOn, $reason) {
            $change = $this->recordSalaryChange($employee, $employee->base_salary, $newSalary, $effectiveOn, $reason);
            $employee->forceFill(['base_salary' => $newSalary])->save();
            $this->audit->log('hr.salary_changed', $employee, ['base_salary' => $change->old_salary], ['base_salary' => $newSalary, 'effective_on' => $effectiveOn]);

            return $change;
        });
    }

    /** Used by PayrollService when the salary is set on the employee form. */
    public function recordSalaryChange(Employee $employee, int $old, int $new, ?string $effectiveOn = null, ?string $reason = null): SalaryChange
    {
        return SalaryChange::create(['employee_id' => $employee->id, 'old_salary' => $old, 'new_salary' => $new,
            'effective_on' => $effectiveOn ?: today()->toDateString(), 'reason' => $reason, 'changed_by' => auth()->id()]);
    }

    // ------------------------------------------------------------- attendance

    /** Manager records/corrects a day. Statuses: present|late|absent|half_day|holiday. Times are HH:MM on work_date. */
    public function markAttendance(Employee $employee, string $date, string $status, ?string $checkIn = null, ?string $checkOut = null, ?string $notes = null): AttendanceRecord
    {
        Gate::authorize('hr.manage');
        $this->branches->authorize($employee->branch_id);
        Validator::make(compact('date', 'status', 'checkIn', 'checkOut', 'notes'), [
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'status' => ['required', Rule::in(AttendanceRecord::STATUSES)],
            'checkIn' => ['nullable', 'date_format:H:i'],
            'checkOut' => ['nullable', 'date_format:H:i', 'after:checkIn'],
            'notes' => ['nullable', 'string', 'max:255'],
        ])->validate();
        if ($employee->hired_on && $date < $employee->hired_on->toDateString()) {
            throw ValidationException::withMessages(['date' => __('The date is before the hire date.')]);
        }
        $in = $checkIn ? Carbon::parse("$date $checkIn") : null;
        $out = $checkOut ? Carbon::parse("$date $checkOut") : null;
        if (in_array($status, ['absent', 'holiday'], true)) {
            [$in, $out] = [null, null];
        }

        return DB::transaction(function () use ($employee, $date, $status, $in, $out, $notes) {
            $record = AttendanceRecord::where('employee_id', $employee->id)->where('work_date', $date)->lockForUpdate()->first();
            $original = $record?->getAttributes() ?? [];
            $values = $this->timing($employee, $status, $in, $out) + ['branch_id' => $employee->branch_id, 'notes' => $notes, 'source' => 'manual', 'recorded_by' => auth()->id()];
            $record = $record ? tap($record)->update($values) : AttendanceRecord::create(['employee_id' => $employee->id, 'work_date' => $date] + $values);
            $this->audit->log($original ? 'hr.attendance_corrected' : 'hr.attendance_recorded', $record,
                array_intersect_key($original, $values), ['date' => $date, 'status' => $record->status], $employee->branch_id);

            return $record;
        });
    }

    public function checkIn(User $user): AttendanceRecord
    {
        Gate::forUser($user)->authorize('hr.self');
        $employee = $this->selfEmployee($user);

        return DB::transaction(function () use ($employee) {
            $today = today()->toDateString();
            if (AttendanceRecord::where('employee_id', $employee->id)->where('work_date', $today)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['attendance' => __('You have already checked in today.')]);
            }

            return AttendanceRecord::create(['employee_id' => $employee->id, 'branch_id' => $employee->branch_id, 'work_date' => $today, 'source' => 'self',
                'recorded_by' => auth()->id()] + $this->timing($employee, 'present', now(), null));
        });
    }

    public function checkOut(User $user): AttendanceRecord
    {
        Gate::forUser($user)->authorize('hr.self');
        $employee = $this->selfEmployee($user);

        return DB::transaction(function () use ($employee) {
            $record = AttendanceRecord::where('employee_id', $employee->id)->where('work_date', today()->toDateString())->lockForUpdate()->first();
            if (! $record?->check_in_at || $record->check_out_at) {
                throw ValidationException::withMessages(['attendance' => __('Check in first; you can check out once per day.')]);
            }
            $record->update($this->timing($employee, $record->status, $record->check_in_at, now()));

            return $record;
        });
    }

    /** Lateness from the employee's shift start; worked minutes from in/out. */
    private function timing(Employee $employee, string $status, ?Carbon $in, ?Carbon $out): array
    {
        $late = 0;
        if ($in && $employee->shift_start && in_array($status, ['present', 'late'], true)) {
            $expected = Carbon::parse($in->toDateString().' '.$employee->shift_start);
            $late = max(0, (int) $expected->diffInMinutes($in, false));
            $status = $late > 0 ? 'late' : 'present';
        }

        return ['status' => $status, 'check_in_at' => $in, 'check_out_at' => $out, 'late_minutes' => min($late, 65535),
            'worked_minutes' => $in && $out ? min(65535, max(0, (int) $in->diffInMinutes($out))) : 0];
    }

    public function selfEmployee(User $user): Employee
    {
        $employee = Employee::where('user_id', $user->id)->first();
        if (! $employee || ! $employee->is_active) {
            throw ValidationException::withMessages(['attendance' => __('Your user account is not linked to an active employee record.')]);
        }

        return $employee;
    }

    // ------------------------------------------------------------------ leave

    public function requestLeave(Employee $employee, string $type, string $start, string $end, ?string $reason = null): LeaveRequest
    {
        $own = $employee->user_id && $employee->user_id === auth()->id();
        if ($own) {
            Gate::authorize('hr.self');
        } else {
            Gate::authorize('hr.manage');
            $this->branches->authorize($employee->branch_id);
        }
        Validator::make(compact('type', 'start', 'end', 'reason'), [
            'type' => ['required', Rule::in(LeaveRequest::TYPES)],
            'start' => ['required', 'date_format:Y-m-d'],
            'end' => ['required', 'date_format:Y-m-d', 'after_or_equal:start'],
            'reason' => ['nullable', 'string', 'max:500'],
        ])->validate();
        $days = (int) CarbonImmutable::parse($start)->diffInDays(CarbonImmutable::parse($end)) + 1;
        if ($days > 365) {
            throw ValidationException::withMessages(['end' => __('Leave period is too long.')]);
        }

        return DB::transaction(function () use ($employee, $type, $start, $end, $reason, $days) {
            Employee::whereKey($employee->id)->lockForUpdate()->first(); // serialize requests per employee
            $this->assertNoOverlap($employee, $start, $end);
            if ($type === 'annual') {
                $this->assertBalance($employee, $start, $days);
            }
            $leave = LeaveRequest::create(['employee_id' => $employee->id, 'branch_id' => $employee->branch_id, 'type' => $type, 'start_date' => $start,
                'end_date' => $end, 'days' => $days, 'reason' => $reason, 'status' => 'pending', 'requested_by' => auth()->id()]);
            $this->audit->log('hr.leave_requested', $leave, [], ['type' => $type, 'start' => $start, 'end' => $end, 'days' => $days], $employee->branch_id);

            return $leave;
        });
    }

    public function decideLeave(LeaveRequest $leave, bool $approve, ?string $note = null): LeaveRequest
    {
        Gate::authorize('hr.manage');
        $this->branches->authorize($leave->branch_id);

        return DB::transaction(function () use ($leave, $approve, $note) {
            $leave = LeaveRequest::with('employee')->lockForUpdate()->findOrFail($leave->id);
            if ($leave->status !== 'pending') {
                throw ValidationException::withMessages(['status' => __('Only pending requests can be decided.')]);
            }
            if ($leave->employee->user_id && $leave->employee->user_id === auth()->id()) {
                throw ValidationException::withMessages(['status' => __('You cannot decide your own leave request.')]);
            }
            if ($approve) {
                $this->assertNoOverlap($leave->employee, $leave->start_date->toDateString(), $leave->end_date->toDateString(), $leave->id);
                if ($leave->type === 'annual') {
                    $this->assertBalance($leave->employee, $leave->start_date->toDateString(), $leave->days, $leave->id);
                }
            }
            $leave->update(['status' => $approve ? 'approved' : 'rejected', 'decided_by' => auth()->id(), 'decided_at' => now(), 'decision_note' => $note]);
            $this->audit->log($approve ? 'hr.leave_approved' : 'hr.leave_rejected', $leave, [], ['note' => $note], $leave->branch_id);

            return $leave;
        });
    }

    public function cancelLeave(LeaveRequest $leave): LeaveRequest
    {
        $employee = $leave->employee()->first();
        $own = $employee->user_id && $employee->user_id === auth()->id();
        if ($own && $leave->status === 'pending') {
            Gate::authorize('hr.self');
        } else {
            Gate::authorize('hr.manage');
            $this->branches->authorize($leave->branch_id);
            if ($leave->status === 'approved' && $leave->start_date->toDateString() <= today()->toDateString()) {
                throw ValidationException::withMessages(['status' => __('Leave that has already started cannot be cancelled.')]);
            }
        }
        if (! in_array($leave->status, ['pending', 'approved'], true)) {
            throw ValidationException::withMessages(['status' => __('This request cannot be cancelled.')]);
        }
        $leave->update(['status' => 'cancelled', 'decided_by' => auth()->id(), 'decided_at' => now()]);
        $this->audit->log('hr.leave_cancelled', $leave, [], [], $leave->branch_id);

        return $leave;
    }

    /** Remaining annual leave for the calendar year of $date (approved + pending requests count against it). */
    public function annualBalance(Employee $employee, ?string $date = null, ?int $ignoreId = null): int
    {
        $year = substr($date ?? today()->toDateString(), 0, 4);
        $used = LeaveRequest::where('employee_id', $employee->id)->where('type', 'annual')->whereIn('status', ['pending', 'approved'])
            ->whereBetween('start_date', ["$year-01-01", "$year-12-31"])->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->sum('days');

        return $employee->annual_leave_days - (int) $used;
    }

    private function assertBalance(Employee $employee, string $start, int $days, ?int $ignoreId = null): void
    {
        $balance = $this->annualBalance($employee, $start, $ignoreId);
        if ($days > $balance) {
            throw ValidationException::withMessages(['days' => __('Not enough annual leave balance (:balance days left).', ['balance' => max(0, $balance)])]);
        }
    }

    private function assertNoOverlap(Employee $employee, string $start, string $end, ?int $ignoreId = null): void
    {
        $overlap = LeaveRequest::where('employee_id', $employee->id)->whereIn('status', ['pending', 'approved'])
            ->where('start_date', '<=', $end)->where('end_date', '>=', $start)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists();
        if ($overlap) {
            throw ValidationException::withMessages(['start' => __('This period overlaps another leave request.')]);
        }
    }

    // ---------------------------------------------------------------- reviews

    public function addReview(Employee $employee, array $data): PerformanceReview
    {
        Gate::authorize('hr.manage');
        $this->branches->authorize($employee->branch_id);
        $data = Validator::make($data, [
            'period_start' => ['required', 'date_format:Y-m-d'],
            'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start'],
            'rating' => ['required', 'integer', 'between:1,5'],
            'strengths' => ['nullable', 'string', 'max:2000'],
            'improvements' => ['nullable', 'string', 'max:2000'],
        ])->validate();
        if ($employee->user_id && $employee->user_id === auth()->id()) {
            throw ValidationException::withMessages(['rating' => __('You cannot review yourself.')]);
        }
        $review = PerformanceReview::create($data + ['employee_id' => $employee->id, 'reviewer_id' => auth()->id()]);
        $this->audit->log('hr.review_added', $review, [], ['rating' => $review->rating], $employee->branch_id);

        return $review;
    }

    // -------------------------------------------------------- monthly summary

    /**
     * Attendance/leave figures for a month, used by payroll and reports.
     *
     * @return array{present:int, late:int, half_days:int, absent:int, paid_leave:int, unpaid_leave:int, late_minutes:int, worked_minutes:int}
     */
    public function monthSummary(Employee $employee, int $year, int $month): array
    {
        $from = CarbonImmutable::create($year, $month, 1);

        return $this->summary($employee, $from->toDateString(), $from->endOfMonth()->toDateString());
    }

    public function summary(Employee $employee, string $from, string $to): array
    {
        $att = AttendanceRecord::where('employee_id', $employee->id)->whereBetween('work_date', [$from, $to])
            ->selectRaw('status, COUNT(*) as n, SUM(late_minutes) as late_m, SUM(worked_minutes) as worked_m')->groupBy('status')->get()->keyBy('status');
        $leave = ['paid' => 0, 'unpaid' => 0];
        foreach (LeaveRequest::where('employee_id', $employee->id)->where('status', 'approved')->where('start_date', '<=', $to)->where('end_date', '>=', $from)->get() as $l) {
            $s = max($l->start_date->toDateString(), $from);
            $e = min($l->end_date->toDateString(), $to);
            $days = (int) CarbonImmutable::parse($s)->diffInDays(CarbonImmutable::parse($e)) + 1;
            $leave[$l->type === 'unpaid' ? 'unpaid' : 'paid'] += $days;
        }

        return [
            'present' => (int) ($att['present']->n ?? 0) + (int) ($att['late']->n ?? 0),
            'late' => (int) ($att['late']->n ?? 0),
            'half_days' => (int) ($att['half_day']->n ?? 0),
            'absent' => (int) ($att['absent']->n ?? 0),
            'paid_leave' => $leave['paid'],
            'unpaid_leave' => $leave['unpaid'],
            'late_minutes' => (int) $att->sum('late_m'),
            'worked_minutes' => (int) $att->sum('worked_m'),
        ];
    }

    /**
     * Salary deduction for absences, half days and unpaid leave: base × (absent + unpaid + ½ half-days) / days in month,
     * integer minor units, never more than the base salary.
     */
    public function attendanceDeduction(int $baseSalary, array $summary, int $daysInMonth): int
    {
        $halfUnits = 2 * ($summary['absent'] + $summary['unpaid_leave']) + $summary['half_days'];
        if ($halfUnits <= 0 || $baseSalary <= 0) {
            return 0;
        }

        return min($baseSalary, Money::divRound($baseSalary * $halfUnits, 2 * $daysInMonth));
    }
}
