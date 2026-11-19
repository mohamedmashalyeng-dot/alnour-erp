<?php

namespace App\Modules\Optical\Livewire;

use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Catalog\Models\Product;
use App\Modules\Optical\Models\Prescription;
use App\Modules\Optical\Services\PrescriptionService;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/** Printable prescription card, attachments, void, and lens compatibility check. */
class PrescriptionShow extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $rxId;

    public string $voidReason = '';

    public $file = null;

    public string $lensId = '';

    public function mount(Prescription $prescription): void
    {
        $this->authorize('prescriptions.view');
        app(BranchAccess::class)->authorize($prescription->branch_id);
        $this->rxId = $prescription->id;
    }

    private function rx(): Prescription
    {
        return Prescription::with(['customer', 'branch', 'creator:id,name', 'attachments'])->findOrFail($this->rxId);
    }

    public function void(PrescriptionService $service): void
    {
        $service->void($this->rx(), $this->voidReason);
        $this->voidReason = '';
        $this->dispatch('toast', message: __('Prescription voided.'));
    }

    public function upload(PrescriptionService $service): void
    {
        if (! $this->file) {
            return;
        }
        try {
            $service->attach($this->rx(), $this->file);
        } catch (ValidationException $e) {
            $this->addError('file', collect($e->errors())->flatten()->first());

            return;
        }
        $this->file = null;
        $this->dispatch('toast', message: __('File attached.'));
    }

    public function render(PrescriptionService $service)
    {
        $rx = $this->rx();
        $lens = $this->lensId ? Product::where('product_type', ProductType::OphthalmicLens->value)->find((int) $this->lensId) : null;

        return view('livewire.optical.prescription-show', [
            'rx' => $rx,
            'lenses' => Product::where('product_type', ProductType::OphthalmicLens->value)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'sku']),
            'lens' => $lens,
            'problems' => $lens ? $service->lensProblems($rx, $lens) : [],
        ])->title($rx->number);
    }
}
