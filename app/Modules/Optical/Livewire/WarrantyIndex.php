<?php

namespace App\Modules\Optical\Livewire;

use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Optical\Models\Warranty;
use App\Modules\Optical\Models\WarrantyClaim;
use App\Modules\Optical\Services\WarrantyService;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Warranty lookup (invoice, customer, phone, product) and the claims workflow. */
#[Title('Warranties & claims')]
class WarrantyIndex extends Component
{
    use WithPagination;

    #[Url]
    public string $tab = 'warranties';

    #[Url]
    public string $search = '';

    #[Url]
    public string $claimStatus = 'open';

    #[Locked]
    public ?int $claimFor = null;

    public string $issue = '';

    /** @var array<int, array{resolution:string, notes:string}> */
    public array $decision = [];

    public function mount(): void
    {
        $this->authorize('warranty.view');
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function startClaim(int $id): void
    {
        $this->authorize('warranty.claims');
        $this->claimFor = $id;
        $this->issue = '';
    }

    public function openClaim(WarrantyService $service): void
    {
        try {
            $claim = $service->openClaim(Warranty::findOrFail($this->claimFor), $this->issue);
        } catch (ValidationException $e) {
            $this->addError('issue', collect($e->errors())->flatten()->first());

            return;
        }
        $this->claimFor = null;
        $this->dispatch('toast', message: __('Claim :number opened.', ['number' => $claim->number]));
    }

    public function decide(int $id, bool $approve, WarrantyService $service): void
    {
        $d = $this->decision[$id] ?? ['resolution' => '', 'notes' => ''];
        try {
            $service->decide(WarrantyClaim::findOrFail($id), $approve, ($d['resolution'] ?? '') ?: null, ($d['notes'] ?? '') ?: null);
        } catch (ValidationException $e) {
            $this->addError("claim.$id", collect($e->errors())->flatten()->first());

            return;
        }
        $this->dispatch('toast', message: $approve ? __('Claim approved.') : __('Claim rejected.'));
    }

    public function resolve(int $id, WarrantyService $service): void
    {
        try {
            $service->resolve(WarrantyClaim::findOrFail($id), ($this->decision[$id]['notes'] ?? '') ?: null);
        } catch (ValidationException $e) {
            $this->addError("claim.$id", collect($e->errors())->flatten()->first());

            return;
        }
        $this->dispatch('toast', message: __('Claim resolved.'));
    }

    public function render(BranchAccess $access)
    {
        $ids = $access->allowedIds();
        $term = trim($this->search);
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        return view('livewire.optical.warranties', [
            'warranties' => $this->tab === 'warranties' ? Warranty::with(['sale:id,invoice_number', 'product:id,name,sku', 'customer:id,name,phone', 'branch:id,code'])
                ->whereIn('branch_id', $ids)
                ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->whereHas('sale', fn ($s) => $s->where('invoice_number', 'like', $like))
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $like)->orWhere('phone', 'like', $like))
                    ->orWhereHas('product', fn ($p) => $p->where('name', 'like', $like)->orWhere('sku', 'like', $like))))
                ->latest('id')->paginate(25) : null,
            'claims' => $this->tab === 'claims' ? WarrantyClaim::with(['warranty.product:id,name,sku', 'warranty.customer:id,name,phone', 'warranty.sale:id,invoice_number', 'branch:id,code', 'creator:id,name', 'decider:id,name'])
                ->whereIn('branch_id', $ids)->when($this->claimStatus !== '', fn ($q) => $q->where('status', $this->claimStatus))->latest('id')->paginate(25) : null,
        ]);
    }
}
