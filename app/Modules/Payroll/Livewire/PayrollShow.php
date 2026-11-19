<?php

namespace App\Modules\Payroll\Livewire;

use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Core\Support\Money;
use App\Modules\Payroll\Models\PayrollItem;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Services\PayrollService;
use App\Modules\Treasury\Models\BankAccount;
use App\Modules\Treasury\Models\CashRegister;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class PayrollShow extends Component
{
    #[Locked]
    public int $runId;

    /** @var array<int, array{bonuses:string, deductions:string, advance_recovery:string}> */
    public array $items = [];

    public string $source = 'bank';

    public string $registerId = '';

    public string $bankId = '';

    public function mount(PayrollRun $run, BranchAccess $access): void
    {
        $this->authorize('payroll.view');
        $access->authorize($run->branch_id);
        $this->runId = $run->id;
        foreach ($run->items as $i) {
            $this->items[$i->id] = ['bonuses' => money_input($i->bonuses), 'deductions' => money_input($i->deductions), 'advance_recovery' => money_input($i->advance_recovery)];
        }
    }

    public function saveItem(int $itemId, PayrollService $payroll): void
    {
        $item = PayrollItem::where('payroll_run_id', $this->runId)->findOrFail($itemId);
        $v = $this->items[$itemId] ?? [];
        $parse = function (?string $s, string $f) use ($itemId) {
            $s = trim((string) $s) ?: '0';
            if (! Money::isValid($s) || Money::parse($s) < 0) {
                throw ValidationException::withMessages(["items.$itemId.$f" => __('Invalid amount.')]);
            }

            return Money::parse($s);
        };
        try {
            $payroll->updateItem($item, $parse($v['bonuses'] ?? '', 'bonuses'), $parse($v['deductions'] ?? '', 'deductions'), $parse($v['advance_recovery'] ?? '', 'advance_recovery'));
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(["items.$itemId" => collect($e->errors())->flatten()->all()]);
        }
        $this->dispatch('toast', message: __('Saved successfully.'));
    }

    public function approve(PayrollService $payroll): void
    {
        $payroll->approve(PayrollRun::findOrFail($this->runId));
        $this->dispatch('toast', message: __('Payroll approved and posted.'));
    }

    public function pay(PayrollService $payroll): void
    {
        $payroll->pay(PayrollRun::findOrFail($this->runId), $this->source, $this->registerId ? (int) $this->registerId : null, $this->bankId ? (int) $this->bankId : null);
        $this->dispatch('toast', message: __('Salaries paid.'));
    }

    public function render()
    {
        $run = PayrollRun::with(['items.employee', 'branch'])->findOrFail($this->runId);

        return view('livewire.payroll.show', [
            'run' => $run,
            'registers' => CashRegister::where('branch_id', $run->branch_id)->where('is_active', true)->get(),
            'banks' => BankAccount::where('is_active', true)->get(),
        ])->title(__('Payroll').' '.$run->number);
    }
}
