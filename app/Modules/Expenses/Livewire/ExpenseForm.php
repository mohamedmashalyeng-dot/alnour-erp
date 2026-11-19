<?php

namespace App\Modules\Expenses\Livewire;

use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Core\Support\Money;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Expenses\Models\ExpenseCategory;
use App\Modules\Expenses\Services\ExpenseService;
use App\Modules\Treasury\Models\BankAccount;
use App\Modules\Treasury\Models\CashRegister;
use App\Modules\Treasury\Services\TreasuryService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

class ExpenseForm extends Component
{
    use WithFileUploads;

    #[Locked]
    public ?int $expenseId = null;

    #[Locked]
    public string $uuid = '';

    public array $form = [];

    public array $uploads = [];

    public function mount(BranchAccess $access, ?Expense $expense = null): void
    {
        $this->authorize('expenses.create');
        $this->uuid = (string) Str::uuid();
        if ($expense?->exists) {
            $access->authorize($expense->branch_id);
            abort_unless(in_array($expense->status, ['draft', 'rejected'], true), 403);
            $this->expenseId = $expense->id;
            $this->form = [
                'branch_id' => (string) $expense->branch_id, 'expense_category_id' => (string) $expense->expense_category_id,
                'expense_date' => $expense->expense_date->toDateString(), 'description' => $expense->description,
                'amount' => money_input($expense->amount), 'tax_amount' => money_input($expense->tax_amount),
                'payment_source' => $expense->payment_source, 'cash_register_id' => (string) $expense->cash_register_id,
                'bank_account_id' => (string) $expense->bank_account_id, 'reference' => (string) $expense->reference,
            ];
        } else {
            $this->form = ['branch_id' => (string) $access->currentOperational(), 'expense_category_id' => '', 'expense_date' => today()->toDateString(),
                'description' => '', 'amount' => '', 'tax_amount' => '', 'payment_source' => 'cash', 'cash_register_id' => '', 'bank_account_id' => '', 'reference' => ''];
        }
    }

    public function save(ExpenseService $service, bool $submit = false)
    {
        $this->validate(['uploads' => ['array', 'max:5'], 'uploads.*' => ['file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120']]);
        $payload = [
            'branch_id' => (int) $this->form['branch_id'],
            'expense_category_id' => (int) $this->form['expense_category_id'] ?: null,
            'expense_date' => $this->form['expense_date'],
            'description' => $this->form['description'],
            'amount' => $this->money($this->form['amount'], 'amount'),
            'tax_amount' => $this->money($this->form['tax_amount'], 'tax_amount'),
            'payment_source' => $this->form['payment_source'],
            'cash_register_id' => $this->form['cash_register_id'] ? (int) $this->form['cash_register_id'] : null,
            'bank_account_id' => $this->form['bank_account_id'] ? (int) $this->form['bank_account_id'] : null,
            'reference' => $this->form['reference'] ?: null,
            'uuid' => $this->uuid,
        ];
        try {
            $expense = $service->save($payload, $this->expenseId ? Expense::findOrFail($this->expenseId) : null);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => ['form.'.$k => $m])->all());
        }
        foreach ($this->uploads as $file) {
            $service->attach($expense, $file);
        }
        if ($submit) {
            $service->submit($expense);
        }
        session()->flash('success', __('Expense :number saved.', ['number' => $expense->number]));

        return $this->redirectRoute('expenses.show', $expense);
    }

    public function saveAndSubmit(ExpenseService $service)
    {
        return $this->save($service, true);
    }

    private function money(?string $v, string $field): int
    {
        $v = trim((string) $v);
        if ($v === '') {
            return 0;
        }
        if (! Money::isValid($v) || Money::parse($v) < 0) {
            throw ValidationException::withMessages(['form.'.$field => __('Invalid amount.')]);
        }

        return Money::parse($v);
    }

    public function render(BranchAccess $access)
    {
        $branchId = (int) ($this->form['branch_id'] ?? 0);
        if ($branchId && $access->canAccess($branchId)) {
            app(TreasuryService::class)->defaultRegister($branchId);
        }

        return view('livewire.expenses.form', [
            'branches' => $access->allowedBranches(),
            'categories' => ExpenseCategory::where('is_active', true)->orderBy('name')->get(),
            'registers' => CashRegister::where('is_active', true)->where('branch_id', (int) ($this->form['branch_id'] ?? 0))->get(),
            'banks' => BankAccount::where('is_active', true)->get(),
        ])->title($this->expenseId ? __('Edit expense') : __('New expense'));
    }
}
