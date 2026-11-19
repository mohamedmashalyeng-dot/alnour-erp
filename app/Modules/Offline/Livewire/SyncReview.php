<?php

namespace App\Modules\Offline\Livewire;

use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Offline\Models\PosDevice;
use App\Modules\Offline\Models\SyncConflict;
use App\Modules\Offline\Models\SyncLog;
use App\Modules\Offline\Services\OfflineSyncService;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Administrative conflict resolution for offline sales, device management and the sync log. */
#[Title('Offline sync review')]
class SyncReview extends Component
{
    use WithPagination;

    #[Url]
    public string $tab = 'conflicts';

    #[Url]
    public string $status = 'open';

    #[Locked]
    public ?int $resolving = null;

    public string $resolution = '';

    public function mount(): void
    {
        $this->authorize('sync.review');
    }

    public function startResolve(int $id): void
    {
        $this->resolving = $id;
        $this->resolution = '';
    }

    public function resolve(OfflineSyncService $sync): void
    {
        $this->validate(['resolution' => ['required', 'string', 'max:255']]);
        $sync->resolve(SyncConflict::findOrFail($this->resolving), $this->resolution);
        $this->resolving = null;
        $this->dispatch('toast', message: __('Conflict resolved.'));
    }

    public function revoke(int $id, OfflineSyncService $sync): void
    {
        $sync->revoke(PosDevice::findOrFail($id));
        $this->dispatch('toast', message: __('Device revoked.'));
    }

    public function render(BranchAccess $access)
    {
        $ids = $access->allowedIds();

        return view('livewire.offline.review', [
            'conflicts' => SyncConflict::with(['sale:id,invoice_number', 'branch:id,code', 'device:id,name', 'user:id,name', 'resolver:id,name'])
                ->whereIn('branch_id', $ids)->when($this->status !== '', fn ($q) => $q->where('status', $this->status))->latest('id')->paginate(20, pageName: 'c'),
            'devices' => PosDevice::with(['branch:id,code', 'user:id,name'])->whereIn('branch_id', $ids)->latest('id')->get(),
            'logs' => SyncLog::with(['device:id,name', 'user:id,name'])->whereHas('device', fn ($q) => $q->whereIn('branch_id', $ids))->latest('id')->paginate(30, pageName: 'l'),
        ]);
    }
}
