<?php

namespace App\Modules\Treasury\Livewire;

use App\Modules\Accounting\Models\Account;
use App\Modules\Branches\Models\Branch;
use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Core\Support\Money;
use App\Modules\Treasury\Models\BankAccount;
use App\Modules\Treasury\Models\CashRegister;
use App\Modules\Treasury\Models\CashShift;
use App\Modules\Treasury\Models\TreasuryMovement;
use App\Modules\Treasury\Services\TreasuryService;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Cash registers, cashier shifts (open / count / close) and cash/bank movements.
 */
#[Title('Cash management')]
class CashIndex extends Component
{
    public string $openFloat = '';

    public ?int $openRegisterId = null;

    public string $counted = '';

    public string $closeNotes = '';

    #[Locked]
    public ?int $closingShiftId = null;

    public bool $showMovement = false;

    #[Locked]
    public string $movementUuid = '';

    public array $movement = [];

    public array $newRegister = ['branch_id' => '', 'name' => ''];

    public function mount(): void
    {
        abort_unless(auth()->user()->canany(['cash.view', 'cash.shift', 'cash.manage']), 403);
        $this->resetMovement();
    }

    private function resetMovement(): void
    {
        $this->movementUuid = (string) Str::uuid();
        $this->movement = ['type' => 'transfer', 'from' => '', 'to' => '', 'counter_account_id' => '', 'amount' => '', 'reference' => '', 'notes' => ''];
    }

    public function openShift(TreasuryService $treasury): void
    {
        $this->validate(['openRegisterId' => ['required', 'integer'], 'openFloat' => ['required', 'string', 'max:20']]);
        $treasury->openShift(CashRegister::findOrFail($this->openRegisterId), $this->money($this->openFloat, 'openFloat'));
        $this->openFloat = '';
        $this->openRegisterId = null;
        $this->dispatch('toast', message: __('Shift opened.'));
    }

    public function startClose(int $shiftId): void
    {
        $this->closingShiftId = $shiftId;
        $this->counted = '';
    }

    public function closeShift(TreasuryService $treasury): void
    {
        $this->validate(['counted' => ['required', 'string', 'max:20'], 'closeNotes' => ['nullable', 'string', 'max:500']]);
        $shift = $treasury->closeShift(CashShift::findOrFail($this->closingShiftId), $this->money($this->counted, 'counted'), $this->closeNotes ?: null);
        $this->closingShiftId = null;
        $this->dispatch('toast', message: __('Shift closed. Variance: :v', ['v' => money($shift->variance)]), type: $shift->variance === 0 ? 'success' : 'error');
    }

    public function createRegister(TreasuryService $treasury, BranchAccess $access): void
    {
        $this->authorize('cash.manage');
        $data = $this->validate(['newRegister.branch_id' => ['required', 'integer'], 'newRegister.name' => ['required', 'string', 'max:100']])['newRegister'];
        $branch = $access->authorizeOperational((int) $data['branch_id']);
        $treasury->createRegister($branch, $data['name'], ! CashRegister::where('branch_id', $branch->id)->exists());
        $this->newRegister = ['branch_id' => '', 'name' => ''];
        $this->dispatch('toast', message: __('Saved successfully.'));
    }

    public function saveMovement(TreasuryService $treasury): void
    {
        $data = $this->validate([
            'movement.type' => ['required', Rule::in(['deposit', 'withdrawal', 'transfer'])],
            'movement.from' => ['nullable', 'string', 'regex:/^(cash|bank):\d+$/'],
            'movement.to' => ['nullable', 'string', 'regex:/^(cash|bank):\d+$/'],
            'movement.counter_account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'movement.amount' => ['required', 'string', 'max:20'],
            'movement.reference' => ['nullable', 'string', 'max:100'],
            'movement.notes' => ['nullable', 'string', 'max:500'],
        ])['movement'];
        $from = $data['type'] === 'deposit' ? null : $this->resolve($data['from']);
        $to = $data['type'] === 'withdrawal' ? null : $this->resolve($data['to']);
        $branchId = ($from instanceof CashRegister ? $from->branch_id : null) ?? ($to instanceof CashRegister ? $to->branch_id : null)
            ?? $from?->branch_id ?? $to?->branch_id ?? app(BranchAccess::class)->currentOperational();

        $treasury->recordMovement([
            'uuid' => $this->movementUuid, 'type' => $data['type'], 'branch_id' => (int) $branchId,
            'from' => $from, 'to' => $to, 'counter_account_id' => $data['type'] === 'transfer' ? null : ($data['counter_account_id'] ?: null),
            'amount' => $this->money($data['amount'], 'movement.amount'), 'reference' => $data['reference'] ?: null, 'notes' => $data['notes'] ?: null,
        ]);
        $this->resetMovement();
        $this->showMovement = false;
        $this->dispatch('toast', message: __('Movement recorded.'));
    }

    private function resolve(?string $ref): CashRegister|BankAccount|null
    {
        if (! $ref) {
            return null;
        }
        [$kind, $id] = explode(':', $ref);

        return $kind === 'cash' ? CashRegister::findOrFail((int) $id) : BankAccount::findOrFail((int) $id);
    }

    private function money(string $v, string $field): int
    {
        if (! Money::isValid($v) || Money::parse($v) < 0) {
            throw ValidationException::withMessages([$field => __('Invalid amount.')]);
        }

        return Money::parse($v);
    }

    public function render(TreasuryService $treasury, BranchAccess $access)
    {
        $branchIds = $access->allowedIds();
        foreach ($access->allowedBranches() as $b) {
            $treasury->defaultRegister($b->id); // every active branch has at least one register
        }
        $registers = CashRegister::with(['branch:id,code,name,name_en', 'shifts' => fn ($q) => $q->where('status', 'open')->with('user:id,name')])
            ->whereIn('branch_id', $branchIds)->orderBy('branch_id')->get();
        foreach ($registers as $r) {
            $r->setAttribute('balance', $treasury->balance($r->account_id));
            $r->setAttribute('open', $r->shifts->first());
        }
        $closing = $this->closingShiftId ? CashShift::with('register')->find($this->closingShiftId) : null;

        return view('livewire.treasury.cash', [
            'registers' => $registers,
            'banks' => BankAccount::where('is_active', true)->get(),
            'closing' => $closing,
            'expected' => $closing ? $treasury->expectedCash($closing) : null,
            'myShift' => CashShift::with('register:id,name')->where('user_id', auth()->id())->where('status', 'open')->first(),
            'recentShifts' => CashShift::with(['register:id,name,branch_id', 'user:id,name'])->whereHas('register', fn ($q) => $q->whereIn('branch_id', $branchIds))
                ->where('status', 'closed')->latest('closed_at')->limit(15)->get(),
            'movements' => TreasuryMovement::with(['from', 'to', 'counterAccount:id,code,name,name_en', 'user:id,name'])->whereIn('branch_id', $branchIds)->latest('id')->limit(15)->get(),
            'counterAccounts' => Account::where('is_postable', true)->where('is_active', true)->whereNotIn('code', ['1130', '2110'])->orderBy('code')->get(['id', 'code', 'name', 'name_en']),
            'branches' => Branch::whereIn('id', $branchIds)->where('is_active', true)->orderBy('code')->get(),
        ]);
    }
}
