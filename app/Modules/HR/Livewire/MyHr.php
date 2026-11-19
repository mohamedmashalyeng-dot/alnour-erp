<?php

namespace App\Modules\HR\Livewire;

use App\Modules\HR\Models\AttendanceRecord;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\HR\Services\HrService;
use App\Modules\Payroll\Models\Employee;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Employee self-service: check in/out, own attendance and leave. */
#[Title('My attendance & leave')]
class MyHr extends Component
{
    public array $leave = ['type' => 'annual', 'start' => '', 'end' => '', 'reason' => ''];

    public function mount(): void
    {
        $this->authorize('hr.self');
    }

    public function checkIn(HrService $hr): void
    {
        $this->run(fn () => $hr->checkIn(auth()->user()), __('Checked in.'));
    }

    public function checkOut(HrService $hr): void
    {
        $this->run(fn () => $hr->checkOut(auth()->user()), __('Checked out.'));
    }

    public function requestLeave(HrService $hr): void
    {
        try {
            $hr->requestLeave($hr->selfEmployee(auth()->user()), $this->leave['type'], (string) $this->leave['start'], (string) $this->leave['end'], $this->leave['reason'] ?: null);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => ['leave.'.$k => $m])->all());
        }
        $this->leave = ['type' => 'annual', 'start' => '', 'end' => '', 'reason' => ''];
        $this->dispatch('toast', message: __('Leave request submitted.'));
    }

    public function cancel(int $id, HrService $hr): void
    {
        $employee = Employee::where('user_id', auth()->id())->firstOrFail();
        $this->run(fn () => $hr->cancelLeave(LeaveRequest::where('employee_id', $employee->id)->findOrFail($id)), __('Leave cancelled.'));
    }

    private function run(callable $fn, string $ok): void
    {
        try {
            $fn();
            $this->dispatch('toast', message: $ok);
        } catch (ValidationException $e) {
            $this->addError('attendance', collect($e->errors())->flatten()->first());
        }
    }

    public function render(HrService $hr)
    {
        $employee = Employee::where('user_id', auth()->id())->where('is_active', true)->first();

        return view('livewire.hr.me', [
            'employee' => $employee,
            'today' => $employee ? AttendanceRecord::where('employee_id', $employee->id)->where('work_date', today()->toDateString())->first() : null,
            'summary' => $employee ? $hr->monthSummary($employee, (int) today()->format('Y'), (int) today()->format('m')) : null,
            'records' => $employee ? AttendanceRecord::where('employee_id', $employee->id)->where('work_date', '>=', today()->startOfMonth()->toDateString())->orderByDesc('work_date')->get() : collect(),
            'leaves' => $employee ? LeaveRequest::where('employee_id', $employee->id)->latest('start_date')->limit(20)->get() : collect(),
            'balance' => $employee ? $hr->annualBalance($employee) : 0,
        ]);
    }
}
