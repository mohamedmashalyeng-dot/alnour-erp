<?php

namespace App\Modules\Payroll\Livewire;

use App\Models\User;
use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Core\Support\Money;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\PayrollItem;
use App\Modules\Payroll\Services\PayrollService;
use App\Modules\Treasury\Models\BankAccount;
use App\Modules\Treasury\Models\CashRegister;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Employee salary records, advances and salary history. Restricted to payroll permissions. */
#[Title('Employees & salaries')]
class EmployeeIndex extends Component
{
    public bool $showForm = false;

    #[Locked]
    public ?int $editingId = null;

    public array $form = [];

    #[Locked]
    public ?int $advanceFor = null;

    #[Locked]
    public string $advanceUuid = '';

    public array $advance = ['amount' => '', 'source' => 'cash', 'cash_register_id' => '', 'bank_account_id' => '', 'notes' => ''];

    #[Locked]
    public ?int $historyFor = null;

    public function mount(): void
    {
        $this->authorize('payroll.view');
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->editingId = null;
        $this->form = ['branch_id' => (string) app(BranchAccess::class)->currentOperational(), 'user_id' => '', 'name' => '', 'job_title' => '', 'phone' => '', 'base_salary' => '', 'hired_on' => '', 'is_active' => true];
        $this->resetValidation();
    }

    public function create(): void
    {
        $this->authorize('payroll.manage');
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->authorize('payroll.manage');
        $e = Employee::findOrFail($id);
        app(BranchAccess::class)->authorize($e->branch_id);
        $this->editingId = $e->id;
        $this->form = ['branch_id' => (string) $e->branch_id, 'user_id' => (string) $e->user_id, 'name' => $e->name, 'job_title' => $e->job_title, 'phone' => $e->phone,
            'base_salary' => money_input($e->base_salary), 'hired_on' => $e->hired_on?->toDateString(), 'is_active' => $e->is_active];
        $this->showForm = true;
    }

    public function save(PayrollService $payroll): void
    {
        $salary = trim((string) $this->form['base_salary']);
        if (! Money::isValid($salary) || Money::parse($salary) < 0) {
            throw ValidationException::withMessages(['form.base_salary' => __('Invalid amount.')]);
        }
        $data = ['branch_id' => (int) $this->form['branch_id'], 'user_id' => $this->form['user_id'] ? (int) $this->form['user_id'] : null,
            'name' => $this->form['name'], 'job_title' => $this->form['job_title'] ?: null, 'phone' => $this->form['phone'] ?: null,
            'base_salary' => Money::parse($salary), 'hired_on' => $this->form['hired_on'] ?: null, 'is_active' => (bool) $this->form['is_active']];
        try {
            $payroll->saveEmployee($data, $this->editingId ? Employee::findOrFail($this->editingId) : null);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => ['form.'.$k => $m])->all());
        }
        $this->showForm = false;
        $this->dispatch('toast', message: __('Saved successfully.'));
    }

    public function startAdvance(int $id): void
    {
        $this->authorize('payroll.manage');
        $this->advanceFor = $id;
        $this->advanceUuid = (string) Str::uuid();
        $this->advance = ['amount' => '', 'source' => 'cash', 'cash_register_id' => '', 'bank_account_id' => '', 'notes' => ''];
    }

    public function giveAdvance(PayrollService $payroll): void
    {
        $amount = trim((string) $this->advance['amount']);
        if (! Money::isValid($amount) || Money::parse($amount) <= 0) {
            $this->addError('advance.amount', __('Invalid amount.'));

            return;
        }
        $payroll->giveAdvance(Employee::findOrFail($this->advanceFor), Money::parse($amount), $this->advance['source'],
            $this->advance['cash_register_id'] ? (int) $this->advance['cash_register_id'] : null,
            $this->advance['bank_account_id'] ? (int) $this->advance['bank_account_id'] : null,
            $this->advance['notes'] ?: null, $this->advanceUuid);
        $this->advanceFor = null;
        $this->dispatch('toast', message: __('Advance recorded.'));
    }

    public function showHistory(int $id): void
    {
        $this->historyFor = $this->historyFor === $id ? null : $id;
    }

    public function render(BranchAccess $access)
    {
        $ids = $access->allowedIds();
        $employees = Employee::with(['branch:id,code', 'user:id,name'])->whereIn('branch_id', $ids)->orderBy('name')->get();
        $employees->each(fn ($e) => $e->setAttribute('outstanding', $e->outstandingAdvances()));
        $advEmployee = $this->advanceFor ? $employees->firstWhere('id', $this->advanceFor) : null;

        return view('livewire.payroll.employees', [
            'employees' => $employees,
            'branches' => $access->allowedBranches(),
            'users' => User::orderBy('name')->get(['id', 'name']),
            'registers' => $advEmployee ? CashRegister::where('branch_id', $advEmployee->branch_id)->where('is_active', true)->get() : collect(),
            'banks' => BankAccount::where('is_active', true)->get(),
            'history' => $this->historyFor ? PayrollItem::with('run:id,number,year,month,status')->where('employee_id', $this->historyFor)->latest('id')->get() : collect(),
        ]);
    }
}
