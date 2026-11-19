<?php

namespace App\Modules\Accounting\Livewire;

use App\Modules\Accounting\Livewire\Concerns\ReportFilters;
use App\Modules\Accounting\Models\JournalEntry;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Journal entries')]
class JournalIndex extends Component
{
    use ReportFilters, WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $source = '';

    public function mount(): void
    {
        $this->authorize('accounting.view');
        $this->initFilters();
    }

    public function updating(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $ids = $this->branchIds();
        $entries = JournalEntry::query()
            ->with(['branch:id,code', 'creator:id,name'])
            // An entry is visible if any of its lines belongs to an allowed branch.
            ->whereIn('id', fn ($q) => $q->select('journal_entry_id')->from('journal_lines')->whereIn('branch_id', $ids))
            ->whereBetween('entry_date', [$this->fromDate(), $this->toDate()])
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when($this->source === 'manual', fn ($q) => $q->whereNull('source_type'))
            ->when($this->source !== '' && $this->source !== 'manual', fn ($q) => $q->where('source_type', $this->source))
            ->when($this->search !== '', fn ($q) => $q->where(fn ($w) => $w->where('number', 'like', '%'.$this->search.'%')->orWhere('memo', 'like', '%'.$this->search.'%')))
            ->orderByDesc('entry_date')->orderByDesc('id')
            ->paginate(40);

        return view('livewire.accounting.journal-index', ['entries' => $entries] + $this->filterData());
    }
}
