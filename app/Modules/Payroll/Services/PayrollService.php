<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Accounting\Services\LedgerService;
use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Services\DocumentNumberGenerator;
use App\Modules\HR\Services\HrService;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\EmployeeAdvance;
use App\Modules\Payroll\Models\PayrollItem;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Treasury\Models\BankAccount;
use App\Modules\Treasury\Models\CashRegister;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Employee salaries, advances and monthly payroll.
 *
 *  advance : Dr Employee advances / Cr cash|bank
 *  approve : Dr Salaries (base − deductions) + Dr Bonuses / Cr Salaries payable (net) + Cr Employee advances (recovery)
 *  pay     : Dr Salaries payable / Cr cash|bank
 */
class PayrollService
{
    public function __construct(
        private LedgerService $ledger,
        private BranchAccess $branches,
        private DocumentNumberGenerator $numbers,
        private AuditLogger $audit,
        private HrService $hr,
    ) {}

    public function saveEmployee(array $data, ?Employee $employee = null): Employee
    {
        Gate::authorize('payroll.manage');
        $data = Validator::make($data, [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'user_id' => ['nullable', 'integer', 'exists:users,id', Rule::unique('employees', 'user_id')->ignore($employee?->id)],
            'name' => ['required', 'string', 'max:255'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'base_salary' => ['required', 'integer', 'min:0'],
            'hired_on' => ['nullable', 'date'],
            'is_active' => ['boolean'],
        ])->validate();
        $this->branches->authorize((int) $data['branch_id']);
        if ($employee) {
            $this->branches->authorize($employee->branch_id);

            return DB::transaction(function () use ($employee, $data) {
                $original = $employee->getAttributes();
                $oldSalary = $employee->base_salary;
                $employee->update($data);
                if ($oldSalary !== $employee->base_salary) {
                    $this->hr->recordSalaryChange($employee, $oldSalary, $employee->base_salary);
                }
                $this->audit->updated($employee, 'payroll.employee', $original);

                return $employee;
            });
        }

        return DB::transaction(function () use ($data) {
            $employee = Employee::create($data);
            $this->hr->recordSalaryChange($employee, 0, $employee->base_salary, $employee->hired_on?->toDateString(), __('Starting salary'));
            $this->audit->created($employee, 'payroll.employee');

            return $employee;
        });
    }

    public function giveAdvance(Employee $employee, int $amount, string $source, ?int $registerId, ?int $bankId, ?string $notes = null, ?string $uuid = null): EmployeeAdvance
    {
        Gate::authorize('payroll.manage');
        $this->branches->authorizeOperational($employee->branch_id);
        $uuid ??= (string) Str::uuid();
        if ($existing = EmployeeAdvance::where('uuid', $uuid)->first()) {
            return $existing;
        }
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => __('Amount must be greater than zero.')]);
        }
        $credit = $this->sourceAccount($source, $registerId, $bankId, $employee->branch_id);

        return DB::transaction(function () use ($employee, $amount, $source, $registerId, $bankId, $notes, $uuid, $credit) {
            $advance = EmployeeAdvance::create([
                'uuid' => $uuid, 'employee_id' => $employee->id, 'advance_date' => now()->toDateString(), 'amount' => $amount,
                'payment_source' => $source, 'cash_register_id' => $source === 'cash' ? $registerId : null,
                'bank_account_id' => $source === 'bank' ? $bankId : null, 'notes' => $notes, 'user_id' => auth()->id(),
            ]);
            $this->ledger->post([
                'entry_date' => $advance->advance_date, 'branch_id' => $employee->branch_id, 'source' => $advance, 'event' => 'advance',
                'memo' => __('Salary advance — :name', ['name' => $employee->name]),
            ], [
                ['account' => 'employee.advances', 'debit' => $amount, 'party_type' => 'employee', 'party_id' => $employee->id],
                ['account' => $credit, 'credit' => $amount],
            ]);
            $this->audit->log('payroll.advance', $advance, [], ['amount' => $amount], $employee->branch_id);

            return $advance;
        });
    }

    public function createRun(int $branchId, int $year, int $month): PayrollRun
    {
        Gate::authorize('payroll.manage');
        $branch = $this->branches->authorizeOperational($branchId);
        if ($month < 1 || $month > 12 || $year < 2000 || $year > 2100) {
            throw ValidationException::withMessages(['month' => __('Invalid period.')]);
        }
        if (PayrollRun::where(['branch_id' => $branchId, 'year' => $year, 'month' => $month])->exists()) {
            throw ValidationException::withMessages(['month' => __('A payroll run already exists for this branch and month.')]);
        }

        return DB::transaction(function () use ($branch, $year, $month) {
            $run = PayrollRun::create(['number' => $this->numbers->next('payroll', $branch->id, $branch->code), 'branch_id' => $branch->id,
                'year' => $year, 'month' => $month, 'created_by' => auth()->id()]);
            $daysInMonth = Carbon::create($year, $month, 1)->daysInMonth;
            foreach (Employee::where('branch_id', $branch->id)->where('is_active', true)->orderBy('name')->get() as $e) {
                // Absences, half days and approved unpaid leave reduce the salary pro rata (editable while the run is a draft).
                $summary = $this->hr->monthSummary($e, $year, $month);
                $deduction = $this->hr->attendanceDeduction($e->base_salary, $summary, $daysInMonth);
                $run->items()->create(['employee_id' => $e->id, 'base_salary' => $e->base_salary, 'deductions' => $deduction,
                    'attendance_deduction' => $deduction, 'absent_days' => $summary['absent'], 'unpaid_leave_days' => $summary['unpaid_leave'],
                    'net_pay' => $e->base_salary - $deduction,
                    'notes' => $deduction ? __('Attendance: :a absent, :h half days, :u unpaid leave days', ['a' => $summary['absent'], 'h' => $summary['half_days'], 'u' => $summary['unpaid_leave']]) : null]);
            }
            $this->recalc($run);
            $this->audit->log('payroll.run_created', $run, [], ['period' => "$year-$month"], $branch->id);

            return $run;
        });
    }

    public function updateItem(PayrollItem $item, int $bonuses, int $deductions, int $advanceRecovery, ?string $notes = null): PayrollItem
    {
        Gate::authorize('payroll.manage');

        return DB::transaction(function () use ($item, $bonuses, $deductions, $advanceRecovery, $notes) {
            $run = PayrollRun::lockForUpdate()->findOrFail($item->payroll_run_id);
            $this->branches->authorize($run->branch_id);
            if ($run->status !== 'draft') {
                throw ValidationException::withMessages(['status' => __('Only draft payroll runs can be edited.')]);
            }
            if ($bonuses < 0 || $deductions < 0 || $advanceRecovery < 0) {
                throw ValidationException::withMessages(['item' => __('Invalid amount.')]);
            }
            $employee = Employee::findOrFail($item->employee_id);
            if ($advanceRecovery > $employee->outstandingAdvances()) {
                throw ValidationException::withMessages(['item' => __('Advance recovery exceeds the outstanding advances (:max).', ['max' => money($employee->outstandingAdvances())])]);
            }
            $net = $item->base_salary + $bonuses - $deductions - $advanceRecovery;
            if ($deductions > $item->base_salary || $net < 0) {
                throw ValidationException::withMessages(['item' => __('Deductions and recoveries cannot exceed the salary.')]);
            }
            $item->fill(['bonuses' => $bonuses, 'deductions' => $deductions, 'advance_recovery' => $advanceRecovery, 'net_pay' => $net, 'notes' => $notes])->save();
            $this->recalc($run);

            return $item;
        });
    }

    public function approve(PayrollRun $run): PayrollRun
    {
        Gate::authorize('payroll.manage');
        $this->branches->authorizeOperational($run->branch_id);

        return DB::transaction(function () use ($run) {
            /** @var PayrollRun $locked */
            $locked = PayrollRun::lockForUpdate()->with('items.employee')->findOrFail($run->id);
            if ($locked->status !== 'draft') {
                throw ValidationException::withMessages(['status' => __('This payroll run is already approved.')]);
            }
            if ($locked->items->isEmpty()) {
                throw ValidationException::withMessages(['status' => __('This payroll run has no employees.')]);
            }

            $base = $locked->items->sum(fn ($i) => $i->base_salary - $i->deductions);
            $bonuses = $locked->items->sum('bonuses');
            $lines = [
                ['account' => 'salaries.expense', 'debit' => $base],
                ['account' => 'bonuses.expense', 'debit' => $bonuses],
                ['account' => 'salaries.payable', 'credit' => $locked->items->sum('net_pay')],
            ];
            foreach ($locked->items as $item) {
                if ($item->advance_recovery > 0) {
                    $lines[] = ['account' => 'employee.advances', 'credit' => $item->advance_recovery, 'party_type' => 'employee', 'party_id' => $item->employee_id];
                    $this->recoverAdvances($item->employee, $item->advance_recovery);
                }
            }
            $this->ledger->post([
                'entry_date' => Carbon::create($locked->year, $locked->month, 1)->endOfMonth()->toDateString(),
                'branch_id' => $locked->branch_id, 'source' => $locked, 'event' => 'accrual',
                'memo' => __('Payroll :number (:period)', ['number' => $locked->number, 'period' => sprintf('%04d-%02d', $locked->year, $locked->month)]),
            ], $lines);

            $locked->status = 'approved';
            $locked->approved_by = auth()->id();
            $locked->approved_at = now();
            $locked->save();
            $this->audit->log('payroll.approved', $locked, [], ['net' => $locked->total_net], $locked->branch_id);

            return $locked;
        });
    }

    public function pay(PayrollRun $run, string $source, ?int $registerId, ?int $bankId): PayrollRun
    {
        Gate::authorize('payroll.manage');
        $this->branches->authorizeOperational($run->branch_id);
        $credit = $this->sourceAccount($source, $registerId, $bankId, $run->branch_id);

        return DB::transaction(function () use ($run, $source, $registerId, $bankId, $credit) {
            $locked = PayrollRun::lockForUpdate()->findOrFail($run->id);
            if ($locked->status !== 'approved') {
                throw ValidationException::withMessages(['status' => __('Only approved payroll runs can be paid.')]);
            }
            $this->ledger->post([
                'entry_date' => now()->toDateString(), 'branch_id' => $locked->branch_id, 'source' => $locked, 'event' => 'payment',
                'memo' => __('Salary payment :number', ['number' => $locked->number]),
            ], [
                ['account' => 'salaries.payable', 'debit' => $locked->total_net],
                ['account' => $credit, 'credit' => $locked->total_net],
            ]);
            $locked->status = 'paid';
            $locked->payment_source = $source;
            $locked->cash_register_id = $source === 'cash' ? $registerId : null;
            $locked->bank_account_id = $source === 'bank' ? $bankId : null;
            $locked->paid_by = auth()->id();
            $locked->paid_at = now();
            $locked->save();
            $this->audit->log('payroll.paid', $locked, [], ['net' => $locked->total_net, 'source' => $source], $locked->branch_id);

            return $locked;
        });
    }

    private function recoverAdvances(Employee $employee, int $amount): void
    {
        foreach ($employee->advances()->orderBy('advance_date')->orderBy('id')->lockForUpdate()->get() as $adv) {
            $open = $adv->amount - $adv->recovered_amount;
            if ($open <= 0 || $amount <= 0) {
                continue;
            }
            $take = min($open, $amount);
            $adv->recovered_amount += $take;
            $adv->save();
            $amount -= $take;
        }
    }

    private function recalc(PayrollRun $run): void
    {
        $items = $run->items()->get();
        $run->total_gross = $items->sum(fn ($i) => $i->base_salary + $i->bonuses - $i->deductions);
        $run->total_net = $items->sum('net_pay');
        $run->save();
    }

    private function sourceAccount(string $source, ?int $registerId, ?int $bankId, int $branchId): int
    {
        return match ($source) {
            'cash' => CashRegister::where('branch_id', $branchId)->where('is_active', true)->findOr((int) $registerId,
                fn () => throw ValidationException::withMessages(['cash_register_id' => __('Invalid cash register.')]))->account_id,
            'bank' => BankAccount::where('is_active', true)->findOr((int) $bankId,
                fn () => throw ValidationException::withMessages(['bank_account_id' => __('Invalid bank account.')]))->account_id,
            default => throw ValidationException::withMessages(['payment_source' => __('Invalid payment method.')]),
        };
    }
}
