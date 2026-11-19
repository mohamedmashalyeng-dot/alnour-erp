<?php

namespace App\Modules\HR\Livewire;

use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Core\Support\Money;
use App\Modules\HR\Models\AttendanceRecord;
use App\Modules\HR\Services\HrService;
use App\Modules\Payroll\Models\Employee;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Employee HR file: profile, attendance, leave, salary history (restricted) and performance reviews. */
class EmployeeProfile extends Component
{
    #[Locked]
    public int $employeeId;

    #[Url]
    public string $tab = 'profile';

    #[Url]
    public string $month = '';

    public array $form = [];

    public array $salary = ['amount' => '', 'effective_on' => '', 'reason' => ''];

    public array $review = ['period_start' => '', 'period_end' => '', 'rating' => '3', 'strengths' => '', 'improvements' => ''];

    public array $leave = ['type' => 'annual', 'start' => '', 'end' => '', 'reason' => ''];

    public function mount(Employee $employee): void
    {
        $this->authorize('hr.view');
        app(BranchAccess::class)->authorize($employee->branch_id);
        $this->employeeId = $employee->id;
        $this->month = preg_match('/^\d{4}-\d{2}$/', $this->month) ? $this->month : today()->format('Y-m');
        $this->fillForm($employee);
        $this->salary['effective_on'] = today()->toDateString();
    }

    private function employee(): Employee
    {
        $e = Employee::with(['branch', 'user:id,name'])->findOrFail($this->employeeId);
        app(BranchAccess::class)->authorize($e->branch_id);

        return $e;
    }

    private function fillForm(Employee $e): void
    {
        $this->form = [
            'employee_code' => $e->employee_code, 'name' => $e->name, 'job_title' => $e->job_title, 'department' => $e->department, 'phone' => $e->phone,
            'email' => $e->email, 'employment_type' => $e->employment_type, 'employment_status' => $e->employment_status, 'hired_on' => $e->hired_on?->toDateString(),
            'terminated_on' => $e->terminated_on?->toDateString(), 'annual_leave_days' => (string) $e->annual_leave_days,
            'shift_start' => $e->shift_start ? substr($e->shift_start, 0, 5) : '', 'emergency_contact_name' => $e->emergency_contact_name, 'notes' => $e->notes,
        ];
        if (auth()->user()->can('hr.sensitive')) {
            $this->form += ['national_id' => $e->national_id, 'address' => $e->address, 'emergency_contact_phone' => $e->emergency_contact_phone];
        }
    }

    public function saveProfile(HrService $hr): void
    {
        $data = array_map(fn ($v) => $v === '' ? null : $v, $this->form);
        $data['annual_leave_days'] = (int) $this->form['annual_leave_days'];
        $this->wrap(fn () => $hr->updateProfile($this->employee(), $data), 'form.');
        $this->dispatch('toast', message: __('Saved successfully.'));
    }

    public function changeSalary(HrService $hr): void
    {
        $amount = trim((string) $this->salary['amount']);
        if (! Money::isValid($amount)) {
            throw ValidationException::withMessages(['salary.new_salary' => __('Invalid amount.')]);
        }
        $this->wrap(fn () => $hr->changeSalary($this->employee(), Money::parse($amount), (string) $this->salary['effective_on'], $this->salary['reason'] ?: null), 'salary.');
        $this->salary = ['amount' => '', 'effective_on' => today()->toDateString(), 'reason' => ''];
        $this->dispatch('toast', message: __('Salary updated.'));
    }

    public function addReview(HrService $hr): void
    {
        $this->wrap(fn () => $hr->addReview($this->employee(), $this->review), 'review.');
        $this->review = ['period_start' => '', 'period_end' => '', 'rating' => '3', 'strengths' => '', 'improvements' => ''];
        $this->dispatch('toast', message: __('Saved successfully.'));
    }

    public function requestLeave(HrService $hr): void
    {
        $this->wrap(fn () => $hr->requestLeave($this->employee(), $this->leave['type'], (string) $this->leave['start'], (string) $this->leave['end'], $this->leave['reason'] ?: null), 'leave.');
        $this->leave = ['type' => 'annual', 'start' => '', 'end' => '', 'reason' => ''];
        $this->dispatch('toast', message: __('Leave request submitted.'));
    }

    private function wrap(callable $fn, string $prefix): void
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => [$prefix.$k => $m])->all());
        }
    }

    public function render(HrService $hr)
    {
        $e = $this->employee();
        [$y, $m] = array_map('intval', explode('-', $this->month));
        $user = auth()->user();
        $canSalary = $user->can('hr.sensitive') || $user->can('payroll.view');
        $monthStart = sprintf('%04d-%02d-01', $y, $m);

        return view('livewire.hr.profile', [
            'employee' => $e,
            'summary' => $hr->monthSummary($e, $y, $m),
            'attendance' => $this->tab === 'attendance' ? AttendanceRecord::where('employee_id', $e->id)->whereBetween('work_date', [$monthStart, date('Y-m-t', strtotime($monthStart))])->orderBy('work_date')->get() : collect(),
            'leaves' => $this->tab === 'leave' ? $e->leaveRequests()->with('decider:id,name')->latest('start_date')->get() : collect(),
            'balance' => $hr->annualBalance($e),
            'canSalary' => $canSalary,
            'salaryHistory' => $this->tab === 'salary' && $canSalary ? $e->salaryChanges()->with('changer:id,name')->latest('id')->get() : collect(),
            'reviews' => $this->tab === 'reviews' ? $e->reviews()->with('reviewer:id,name')->latest('period_end')->get() : collect(),
        ])->title($e->name);
    }
}
