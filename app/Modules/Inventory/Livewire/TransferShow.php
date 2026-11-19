<?php

namespace App\Modules\Inventory\Livewire;

use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Inventory\Enums\TransferStatus;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Services\StockTransferService;
use Livewire\Attributes\Locked;
use Livewire\Component;

class TransferShow extends Component
{
    #[Locked]
    public int $transferId;

    public string $cancelReason = '';

    public bool $showCancel = false;

    public function mount(StockTransfer $transfer, BranchAccess $access): void
    {
        $this->authorize('transfers.view');
        abort_unless($access->canAccess($transfer->from_branch_id) || $access->canAccess($transfer->to_branch_id), 403);
        $this->transferId = $transfer->id;
    }

    private function transfer(): StockTransfer
    {
        return StockTransfer::findOrFail($this->transferId);
    }

    public function approve(StockTransferService $service): void
    {
        $service->approve($this->transfer());
        $this->dispatch('toast', message: __('Transfer approved.'));
    }

    public function dispatchTransfer(StockTransferService $service): void
    {
        $service->dispatch($this->transfer());
        $this->dispatch('toast', message: __('Transfer dispatched.'));
    }

    public function receive(StockTransferService $service): void
    {
        $service->receive($this->transfer());
        $this->dispatch('toast', message: __('Transfer received.'));
    }

    public function cancel(StockTransferService $service): void
    {
        $this->validate(['cancelReason' => ['required', 'string', 'max:255']]);
        $service->cancel($this->transfer(), $this->cancelReason);
        $this->showCancel = false;
        $this->dispatch('toast', message: __('Transfer cancelled.'));
    }

    public function render(BranchAccess $access)
    {
        $transfer = StockTransfer::with(['items.product:id,name,name_en,sku,barcode', 'fromBranch', 'toBranch', 'creator:id,name', 'approver:id,name', 'dispatcher:id,name', 'receiver:id,name', 'canceller:id,name'])
            ->findOrFail($this->transferId);
        $user = auth()->user();
        $atSource = $access->canAccess($transfer->from_branch_id);
        $atDest = $access->canAccess($transfer->to_branch_id);

        return view('livewire.inventory.transfers.show', [
            'transfer' => $transfer,
            'canApprove' => $transfer->status === TransferStatus::Pending && $atSource && $user->can('transfers.approve'),
            'canDispatch' => $transfer->status === TransferStatus::Approved && $atSource && $user->can('transfers.dispatch'),
            'canReceive' => $transfer->status === TransferStatus::Dispatched && $atDest && $user->can('transfers.receive'),
            'canCancel' => in_array($transfer->status, [TransferStatus::Pending, TransferStatus::Approved], true) && $atSource && $user->can('transfers.cancel'),
        ])->title(__('Transfer').' '.$transfer->number);
    }
}
