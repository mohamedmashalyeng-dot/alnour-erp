<?php

namespace App\Modules\HR\Livewire;

use App\Modules\Branches\Services\BranchAccess;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\HR\Services\HrService;
use App\Modules\Payroll\Models\Employee;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Leave requests: managers decide; new requests can be entered on behalf of an employee. */
#[Title('Leave requests')]
class LeaveIndex extends Component
{
    use WithPagination;

    #[Url]
    public string $status = 'pending';

    public array $form = ['employee_id' => '', 'type' => 'annual', 'start' => '', 'end' => '', 'reason' => ''];

    public array $notes = [];

    public function mount(): void
    {
        $this->authorize('hr.view');
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function submit(HrService $hr): void
    {
        $this->authorize('hr.manage');
        $employee = Employee::find((int) $this->form['employee_id']);
        if (! $employee) {
            throw ValidationException::withMessages(['form.employee_id' => __('Choose an employee.')]);
        }
        try {
            $hr->requestLeave($employee, $this->form['type'], (string) $this->form['start'], (string) $this->form['end'], $this->form['reason'] ?: null);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => ['form.'.$k => $m])->all());
        }
        $this->form = ['employee_id' => '', 'type' => 'annual', 'start' => '', 'end' => '', 'reason' => ''];
        $this->dispatch('toast', message: __('Leave request submitted.'));
    }

    public function decide(int $id, bool $approve, HrService $hr): void
    {
        try {
            $hr->decideLeave(LeaveRequest::findOrFail($id), $approve, ($this->notes[$id] ?? '') ?: null);
        } catch (ValidationException $e) {
            $this->addError("leave.$id", collect($e->errors())->flatten()->first());

            return;
        }
        $this->dispatch('toast', message: $approve ? __('Leave approved.') : __('Leave rejected.'));
    }

    public function cancel(int $id, HrService $hr): void
    {
        try {
            $hr->cancelLeave(LeaveRequest::findOrFail($id));
        } catch (ValidationException $e) {
            $this->addError("leave.$id", collect($e->errors())->flatten()->first());

            return;
        }
        $this->dispatch('toast', message: __('Leave cancelled.'));
    }

    public function render(BranchAccess $access)
    {
        $ids = $access->allowedIds();

        return view('livewire.hr.leave', [
            'requests' => LeaveRequest::with(['employee:id,name,user_id', 'branch:id,code', 'decider:id,name'])->whereIn('branch_id', $ids)
                ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))->latest('start_date')->paginate(25),
            'employees' => Employee::whereIn('branch_id', $ids)->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
