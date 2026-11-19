<?php

namespace App\Modules\Core\Livewire;

use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Core\Models\AuditLog;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Audit log')]
class AuditLogIndex extends Component
{
    use WithPagination;

    #[Url]
    public string $event = '';

    public ?int $expanded = null;

    public function mount(): void
    {
        $this->authorize('audit.view');
    }

    public function updatingEvent(): void
    {
        $this->resetPage();
    }

    public function render(BranchAccess $access)
    {
        $logs = AuditLog::query()
            ->with('user:id,name')
            // Entries without a branch (e.g. product changes) are company-wide; branch entries are isolated.
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhereIn('branch_id', $access->allowedIds()))
            ->when($this->event !== '', fn ($q) => $q->where('event', 'like', $this->event.'%'))
            ->latest('id')
            ->paginate(50);

        return view('livewire.core.audit-log', ['logs' => $logs]);
    }
}
