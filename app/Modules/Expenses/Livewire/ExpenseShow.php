<?php

namespace App\Modules\Expenses\Livewire;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Core\Models\Attachment;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Expenses\Services\ExpenseService;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

class ExpenseShow extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $expenseId;

    public string $rejectReason = '';

    public $upload;

    public function mount(Expense $expense, BranchAccess $access): void
    {
        $this->authorize('expenses.view');
        $access->authorize($expense->branch_id);
        $this->expenseId = $expense->id;
    }

    private function expense(): Expense
    {
        return Expense::findOrFail($this->expenseId);
    }

    public function submit(ExpenseService $s): void
    {
        $s->submit($this->expense());
        $this->dispatch('toast', message: __('Submitted for approval.'));
    }

    public function approve(ExpenseService $s): void
    {
        $s->approve($this->expense());
        $this->dispatch('toast', message: __('Expense approved and posted.'));
    }

    public function reject(ExpenseService $s): void
    {
        $this->validate(['rejectReason' => ['required', 'string', 'max:255']]);
        $s->reject($this->expense(), $this->rejectReason);
        $this->dispatch('toast', message: __('Expense rejected.'));
    }

    public function attach(ExpenseService $s): void
    {
        $this->validate(['upload' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120']]);
        $s->attach($this->expense(), $this->upload);
        $this->upload = null;
    }

    public function removeAttachment(int $id, ExpenseService $s): void
    {
        $s->removeAttachment(Attachment::where('attachable_type', 'expense')->where('attachable_id', $this->expenseId)->findOrFail($id));
    }

    public function render()
    {
        $expense = Expense::with(['category', 'branch', 'creator:id,name', 'approver:id,name', 'attachments', 'cashRegister:id,name', 'bankAccount:id,name'])->findOrFail($this->expenseId);
        $user = auth()->user();

        return view('livewire.expenses.show', [
            'e' => $expense,
            'entry' => JournalEntry::where(['source_type' => 'expense', 'source_id' => $expense->id])->first(),
            'canEdit' => $user->can('expenses.create') && in_array($expense->status, ['draft', 'rejected'], true),
            'canApprove' => $user->can('expenses.approve') && $expense->status === 'submitted' && ($expense->created_by !== $user->id || $user->isSuperAdmin()),
        ])->title(__('Expense').' '.$expense->number);
    }
}
