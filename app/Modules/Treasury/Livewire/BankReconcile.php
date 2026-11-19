<?php

namespace App\Modules\Treasury\Livewire;

use App\Modules\Core\Support\Money;
use App\Modules\Treasury\Models\BankAccount;
use App\Modules\Treasury\Models\BankReconciliation;
use App\Modules\Treasury\Services\TreasuryService;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** Tick ledger lines that appear on the bank statement until the reconciled balance equals the statement. */
class BankReconcile extends Component
{
    #[Locked]
    public int $bankId;

    public string $statementDate = '';

    public string $statementBalance = '';

    /** @var list<int|string> */
    public array $selected = [];

    public function mount(BankAccount $bankAccount): void
    {
        $this->authorize('bank.reconcile');
        $this->bankId = $bankAccount->id;
        $this->statementDate = today()->toDateString();
    }

    public function reconcile(TreasuryService $treasury): void
    {
        $this->validate(['statementDate' => ['required', 'date'], 'statementBalance' => ['required', 'string', 'max:20']]);
        $v = trim($this->statementBalance);
        if (! Money::isValid($v)) {
            $this->addError('statementBalance', __('Invalid amount.'));

            return;
        }
        $treasury->reconcile(BankAccount::findOrFail($this->bankId), $this->statementDate, Money::parse($v), array_map('intval', $this->selected));
        $this->selected = [];
        $this->dispatch('toast', message: __('Reconciliation saved.'));
    }

    public function render(TreasuryService $treasury)
    {
        $bank = BankAccount::findOrFail($this->bankId);
        $lines = $treasury->unreconciledLines($bank, $this->statementDate ?: null);
        $selectedSum = $lines->whereIn('id', array_map('intval', $this->selected))->sum(fn ($l) => $l->debit - $l->credit);
        $reconciled = $treasury->reconciledBalance($bank);
        $target = Money::isValid(trim($this->statementBalance) ?: '0') ? Money::parse(trim($this->statementBalance) ?: '0') : 0;

        return view('livewire.treasury.reconcile', [
            'bank' => $bank,
            'lines' => $lines,
            'reconciled' => $reconciled,
            'after' => $reconciled + $selectedSum,
            'difference' => $target - ($reconciled + $selectedSum),
            'history' => BankReconciliation::with('user:id,name')->where('bank_account_id', $bank->id)->latest('id')->limit(10)->get(),
        ])->title(__('Reconcile').' — '.$bank->name);
    }
}
