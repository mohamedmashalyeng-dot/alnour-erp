<?php

namespace App\Modules\Optical\Livewire;

use App\Modules\Customers\Models\Customer;
use App\Modules\Optical\Models\LoyaltyTransaction;
use App\Modules\Optical\Services\LoyaltyService;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Loyalty balances, tiers and the points ledger; manual adjustments need loyalty.adjust. */
#[Title('Loyalty program')]
class LoyaltyIndex extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public ?int $customer = null;

    public array $adjust = ['points' => '', 'notes' => ''];

    public function mount(): void
    {
        $this->authorize('loyalty.view');
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function select(int $id): void
    {
        $this->customer = $id;
    }

    public function saveAdjustment(LoyaltyService $loyalty): void
    {
        $points = trim((string) $this->adjust['points']);
        if (! preg_match('/^-?\d{1,7}$/', $points)) {
            throw ValidationException::withMessages(['adjust.points' => __('Enter a whole number of points (negative to deduct).')]);
        }
        try {
            $loyalty->adjust(Customer::findOrFail($this->customer), (int) $points, (string) $this->adjust['notes']);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['adjust.points' => collect($e->errors())->flatten()->first()]);
        }
        $this->adjust = ['points' => '', 'notes' => ''];
        $this->dispatch('toast', message: __('Saved successfully.'));
    }

    public function render(LoyaltyService $loyalty)
    {
        $term = trim($this->search);
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
        $selected = $this->customer ? Customer::find($this->customer) : null;

        return view('livewire.optical.loyalty', [
            'enabled' => $loyalty->enabled(),
            'customers' => Customer::where('is_walk_in', false)
                ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('phone', 'like', $like)->orWhere('code', 'like', $like)))
                ->orderByDesc('loyalty_points')->orderBy('name')->paginate(20),
            'selected' => $selected,
            'tier' => $selected ? $loyalty->tier($selected) : null,
            'ledger' => $selected ? LoyaltyTransaction::with('user:id,name')->where('customer_id', $selected->id)->latest('id')->limit(100)->get() : collect(),
            'loyalty' => $loyalty,
        ]);
    }
}
