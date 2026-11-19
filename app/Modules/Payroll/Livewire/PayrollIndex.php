<?php

namespace App\Modules\Payroll\Livewire;

use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Services\PayrollService;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Payroll')]
class PayrollIndex extends Component
{
    public ?int $branchId = null;

    public string $period = '';

    public function mount(BranchAccess $access): void
    {
        $this->authorize('payroll.view');
        $this->branchId = $access->currentOperational();
        $this->period = today()->format('Y-m');
    }

    public function create(PayrollService $payroll)
    {
        $this->validate(['branchId' => ['required', 'integer'], 'period' => ['required', 'regex:/^\d{4}-\d{2}$/']]);
        [$y, $m] = array_map('intval', explode('-', $this->period));
        $run = $payroll->createRun((int) $this->branchId, $y, $m);

        return $this->redirectRoute('payroll.show', $run);
    }

    public function render(BranchAccess $access)
    {
        return view('livewire.payroll.index', [
            'runs' => PayrollRun::with('branch:id,code')->withCount('items')->whereIn('branch_id', $access->allowedIds())->orderByDesc('year')->orderByDesc('month')->get(),
            'branches' => $access->allowedBranches(),
        ]);
    }
}
