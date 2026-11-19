<?php

namespace App\Modules\Accounting\Livewire\Concerns;

use App\Modules\Branches\Services\BranchAccess;
use Livewire\Attributes\Url;

/**
 * Date range + branch filter shared by financial reports. Branch 0 = company-wide (requires
 * branches.access-all). The branch is re-authorized on every render; the client value is never trusted.
 */
trait ReportFilters
{
    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public ?int $branch = null;

    protected function initFilters(string $defaultFrom = 'month'): void
    {
        $this->from = $this->validDate($this->from) ?? ($defaultFrom === 'year' ? today()->startOfYear() : today()->startOfMonth())->toDateString();
        $this->to = $this->validDate($this->to) ?? today()->toDateString();
        $this->branch ??= app(BranchAccess::class)->current();
    }

    /** @return list<int> */
    protected function branchIds(): array
    {
        $access = app(BranchAccess::class);
        if ($this->branch) {
            $access->authorize((int) $this->branch);

            return [(int) $this->branch];
        }
        abort_unless(auth()->user()->canAccessAllBranches(), 403);

        return $access->allowedIds();
    }

    protected function fromDate(): string
    {
        return $this->validDate($this->from) ?? today()->startOfMonth()->toDateString();
    }

    protected function toDate(): string
    {
        return $this->validDate($this->to) ?? today()->toDateString();
    }

    protected function validDate(?string $v): ?string
    {
        return $v && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) ? $v : null;
    }

    protected function filterData(): array
    {
        return [
            'branchOptions' => app(BranchAccess::class)->allowedBranches(activeOnly: false),
            'canAll' => auth()->user()->canAccessAllBranches(),
        ];
    }
}
