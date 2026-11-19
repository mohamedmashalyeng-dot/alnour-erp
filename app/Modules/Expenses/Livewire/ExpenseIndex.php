<?php

namespace App\Modules\Expenses\Livewire;

use App\Modules\Accounting\Livewire\Concerns\ReportFilters;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Expenses\Models\ExpenseCategory;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Expense register + report by category and branch. */
#[Title('Expenses')]
class ExpenseIndex extends Component
{
    use ReportFilters, WithPagination;

    #[Url]
    public string $status = '';

    #[Url]
    public string $category = '';

    public function mount(): void
    {
        $this->authorize('expenses.view');
        $this->initFilters();
    }

    public function updating(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $ids = $this->branchIds();
        $base = Expense::query()->whereIn('branch_id', $ids)
            ->whereBetween('expense_date', [$this->fromDate(), $this->toDate()])
            ->when($this->category !== '', fn ($q) => $q->where('expense_category_id', (int) $this->category));

        $byCategory = (clone $base)->where('status', 'approved')
            ->join('expense_categories', 'expense_categories.id', '=', 'expenses.expense_category_id')
            ->groupBy('expense_categories.id', 'expense_categories.name', 'expense_categories.name_en')
            ->selectRaw('expense_categories.name, expense_categories.name_en, SUM(expenses.amount) as amount, COUNT(*) as n')
            ->orderByDesc('amount')->get();

        return view('livewire.expenses.index', [
            'expenses' => (clone $base)->with(['category', 'branch:id,code', 'creator:id,name'])
                ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
                ->latest('expense_date')->latest('id')->paginate(30),
            'byCategory' => $byCategory,
            'categories' => ExpenseCategory::orderBy('name')->get(),
            'pendingCount' => Expense::whereIn('branch_id', $ids)->where('status', 'submitted')->count(),
        ] + $this->filterData());
    }
}
