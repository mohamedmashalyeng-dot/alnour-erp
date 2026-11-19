<?php

namespace App\Modules\HR\Livewire;

use App\Modules\Branches\Services\BranchAccess;
use App\Modules\HR\Models\AttendanceRecord;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\HR\Services\HrService;
use App\Modules\Payroll\Models\Employee;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Daily attendance sheet for one branch. Viewing needs hr.view; recording/correcting needs hr.manage. */
#[Title('Attendance')]
class AttendanceBoard extends Component
{
    #[Url]
    public ?int $branch = null;

    #[Url]
    public string $date = '';

    /** @var array<int, array{status:string, in:string, out:string, notes:string}> */
    public array $rows = [];

    public function mount(BranchAccess $access): void
    {
        $this->authorize('hr.view');
        $this->date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->date) && $this->date <= today()->toDateString() ? $this->date : today()->toDateString();
        $this->branch = $this->branch && $access->canAccess($this->branch) ? $this->branch : ($access->currentOperational() ?? ($access->allowedIds()[0] ?? null));
        $this->loadRows();
    }

    public function updated($name): void
    {
        if (in_array($name, ['branch', 'date'], true)) {
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->date) || $this->date > today()->toDateString()) {
                $this->date = today()->toDateString();
            }
            $this->loadRows();
        }
    }

    private function employees()
    {
        if (! $this->branch) {
            return collect();
        }
        app(BranchAccess::class)->authorize($this->branch);

        return Employee::where('branch_id', $this->branch)->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('hired_on')->orWhere('hired_on', '<=', $this->date))->orderBy('name')->get();
    }

    private function loadRows(): void
    {
        $employees = $this->employees();
        $records = AttendanceRecord::whereIn('employee_id', $employees->pluck('id'))->where('work_date', $this->date)->get()->keyBy('employee_id');
        $this->rows = [];
        foreach ($employees as $e) {
            $r = $records->get($e->id);
            $this->rows[$e->id] = ['status' => $r->status ?? '', 'in' => $r?->check_in_at?->format('H:i') ?? '', 'out' => $r?->check_out_at?->format('H:i') ?? '', 'notes' => $r->notes ?? ''];
        }
    }

    public function save(int $employeeId, HrService $hr): void
    {
        $row = $this->rows[$employeeId] ?? null;
        if (! $row || $row['status'] === '') {
            $this->addError("rows.$employeeId", __('Choose a status.'));

            return;
        }
        try {
            $hr->markAttendance(Employee::findOrFail($employeeId), $this->date, $row['status'], $row['in'] ?: null, $row['out'] ?: null, $row['notes'] ?: null);
        } catch (ValidationException $e) {
            $this->addError("rows.$employeeId", collect($e->errors())->flatten()->first());

            return;
        }
        $this->loadRows();
        $this->dispatch('toast', message: __('Saved successfully.'));
    }

    public function markAllPresent(HrService $hr): void
    {
        $this->authorize('hr.manage');
        $onLeave = $this->onLeave();
        foreach ($this->rows as $id => $row) {
            if ($row['status'] === '' && ! isset($onLeave[$id])) {
                $hr->markAttendance(Employee::findOrFail($id), $this->date, 'present');
            }
        }
        $this->loadRows();
        $this->dispatch('toast', message: __('Saved successfully.'));
    }

    private function onLeave(): array
    {
        return LeaveRequest::whereIn('employee_id', array_keys($this->rows))->where('status', 'approved')
            ->where('start_date', '<=', $this->date)->where('end_date', '>=', $this->date)->pluck('type', 'employee_id')->all();
    }

    public function render(BranchAccess $access)
    {
        return view('livewire.hr.attendance', [
            'employees' => $this->employees(),
            'branches' => $access->allowedBranches(),
            'onLeave' => $this->onLeave(),
        ]);
    }
}
