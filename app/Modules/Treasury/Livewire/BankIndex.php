<?php

namespace App\Modules\Treasury\Livewire;

use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Treasury\Models\BankAccount;
use App\Modules\Treasury\Services\TreasuryService;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Bank accounts')]
class BankIndex extends Component
{
    public bool $showForm = false;

    public array $form = ['name' => '', 'bank_name' => '', 'account_number' => '', 'iban' => '', 'branch_id' => ''];

    public function mount(): void
    {
        abort_unless(auth()->user()->canany(['bank.manage', 'bank.reconcile', 'accounting.view']), 403);
    }

    public function save(TreasuryService $treasury, BranchAccess $access): void
    {
        $this->authorize('bank.manage');
        $data = $this->validate([
            'form.name' => ['required', 'string', 'max:255'],
            'form.bank_name' => ['nullable', 'string', 'max:255'],
            'form.account_number' => ['nullable', 'string', 'max:64'],
            'form.iban' => ['nullable', 'string', 'max:64', 'regex:/^[A-Z0-9 ]+$/i'],
            'form.branch_id' => ['nullable', 'integer'],
        ])['form'];
        if ($data['branch_id']) {
            $access->authorize((int) $data['branch_id']);
        }
        $treasury->createBankAccount($data + ['branch_id' => $data['branch_id'] ?: null]);
        $this->form = ['name' => '', 'bank_name' => '', 'account_number' => '', 'iban' => '', 'branch_id' => ''];
        $this->showForm = false;
        $this->dispatch('toast', message: __('Saved successfully.'));
    }

    public function render(TreasuryService $treasury, BranchAccess $access)
    {
        $banks = BankAccount::with('branch:id,code')->orderBy('name')->get();
        foreach ($banks as $b) {
            $b->setAttribute('balance', $treasury->balance($b->account_id));
            $b->setAttribute('reconciled', $treasury->reconciledBalance($b));
        }

        return view('livewire.treasury.banks', ['banks' => $banks, 'branches' => $access->allowedBranches()]);
    }
}
