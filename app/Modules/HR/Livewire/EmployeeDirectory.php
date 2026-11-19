<?php

namespace App\Modules\HR\Livewire;

use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Payroll\Models\Employee;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Employee directory (non-sensitive fields only), scoped to the user's branches. */
#[Title('Employees')]
class EmployeeDirectory extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = 'active';

    #[Url]
    public ?int $branch = null;

    public function mount(): void
    {
        $this->authorize('hr.view');
    }

    public function updating($name): void
    {
        $this->resetPage();
    }

    public function render(BranchAccess $access)
    {
        $ids = $access->allowedIds();
        if ($this->branch && ! in_array($this->branch, $ids, true)) {
            $this->branch = null;
        }
        $term = trim($this->search);
        $employees = Employee::with(['branch:id,code', 'user:id,name'])->whereIn('branch_id', $this->branch ? [$this->branch] : $ids)
            ->when($this->status !== '', fn ($q) => $q->where('employment_status', $this->status))
            ->when($term !== '', function ($q) use ($term) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
                $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('employee_code', 'like', $like)->orWhere('phone', 'like', $like)
                    ->orWhere('job_title', 'like', $like)->orWhere('department', 'like', $like));
            })
            ->orderBy('name')->paginate(25);

        return view('livewire.hr.directory', ['employees' => $employees, 'branches' => $access->allowedBranches()]);
    }
}
