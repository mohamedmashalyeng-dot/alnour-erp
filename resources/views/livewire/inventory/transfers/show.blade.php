<div>
    <x-page-header :title="__('Transfer').' '.$transfer->number">
        <x-slot:actions>
            @if ($canApprove)<button wire:click="approve" wire:confirm="{{ __('Approve and reserve stock at the source branch?') }}" class="btn-primary">{{ __('Approve') }}</button>@endif
            @if ($canDispatch)<button wire:click="dispatchTransfer" wire:confirm="{{ __('Dispatch? Stock will leave the source branch.') }}" class="btn-primary">{{ __('Dispatch') }}</button>@endif
            @if ($canReceive)<button wire:click="receive" wire:confirm="{{ __('Confirm that all items were received?') }}" class="btn-success">{{ __('Receive') }}</button>@endif
            @if ($canCancel)<button wire:click="$set('showCancel', true)" class="btn-danger">{{ __('Cancel transfer') }}</button>@endif
            <a href="{{ route('transfers.index') }}" class="btn-secondary">{{ __('Back') }}</a>
        </x-slot:actions>
    </x-page-header>

    @error('status') <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ $message }}</div> @enderror
    @error('quantity') <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ $message }}</div> @enderror

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="card xl:col-span-2">
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>{{ __('Product') }}</th><th>{{ __('SKU') }}</th><th>{{ __('Barcode') }}</th><th>{{ __('Quantity') }}</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                    @foreach ($transfer->items as $item)
                        <tr>
                            <td>{{ $item->product->displayName() }}</td>
                            <td class="font-mono text-xs" dir="ltr">{{ $item->product->sku }}</td>
                            <td class="font-mono text-xs" dir="ltr">{{ $item->product->barcode }}</td>
                            <td class="font-semibold">{{ $item->quantity }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                    <tfoot><tr class="bg-slate-50 font-semibold"><td colspan="3">{{ __('Total') }}</td><td>{{ $transfer->items->sum('quantity') }}</td></tr></tfoot>
                </table>
            </div>
        </div>
        <div class="card card-body">
            <div class="mb-4 flex items-center justify-between">
                <span class="text-sm text-slate-500">{{ __('Status') }}</span>
                <x-status-badge :color="$transfer->status->color()" class="text-sm">{{ $transfer->status->label() }}</x-status-badge>
            </div>
            <dl class="space-y-2 text-sm">
                <div class="flex justify-between"><dt class="text-slate-500">{{ __('From') }}</dt><dd class="font-medium">{{ $transfer->fromBranch->displayName() }}</dd></div>
                <div class="flex justify-between"><dt class="text-slate-500">{{ __('To') }}</dt><dd class="font-medium">{{ $transfer->toBranch->displayName() }}</dd></div>
            </dl>
            <ol class="mt-5 space-y-3 border-s-2 border-slate-200 ps-4 text-sm">
                <li><div class="font-medium">{{ __('Created') }}</div><div class="text-xs text-slate-500">{{ $transfer->creator->name }} · <span dir="ltr">{{ $transfer->created_at->format('Y-m-d H:i') }}</span></div></li>
                @if ($transfer->approved_at)<li><div class="font-medium">{{ __('Approved') }}</div><div class="text-xs text-slate-500">{{ $transfer->approver?->name }} · <span dir="ltr">{{ $transfer->approved_at->format('Y-m-d H:i') }}</span></div></li>@endif
                @if ($transfer->dispatched_at)<li><div class="font-medium">{{ __('Dispatched') }}</div><div class="text-xs text-slate-500">{{ $transfer->dispatcher?->name }} · <span dir="ltr">{{ $transfer->dispatched_at->format('Y-m-d H:i') }}</span></div></li>@endif
                @if ($transfer->received_at)<li><div class="font-medium">{{ __('Received') }}</div><div class="text-xs text-slate-500">{{ $transfer->receiver?->name }} · <span dir="ltr">{{ $transfer->received_at->format('Y-m-d H:i') }}</span></div></li>@endif
                @if ($transfer->cancelled_at)<li><div class="font-medium text-rose-700">{{ __('Cancelled') }}</div><div class="text-xs text-slate-500">{{ $transfer->canceller?->name }} · <span dir="ltr">{{ $transfer->cancelled_at->format('Y-m-d H:i') }}</span></div><div class="text-xs">{{ $transfer->cancel_reason }}</div></li>@endif
            </ol>
            @if ($transfer->notes)<p class="mt-4 whitespace-pre-line rounded-lg bg-slate-50 p-3 text-sm">{{ $transfer->notes }}</p>@endif
        </div>
    </div>

    <x-modal show="showCancel" :title="__('Cancel transfer')" max-width="max-w-md">
        <form wire:submit="cancel" class="space-y-4">
            <div>
                <label class="form-label">{{ __('Reason') }} *</label>
                <input wire:model="cancelReason" class="form-input" maxlength="255">
                @error('cancelReason') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div class="flex justify-end gap-2">
                <button type="button" class="btn-secondary" @click="open = false">{{ __('Close') }}</button>
                <button type="submit" class="btn-danger">{{ __('Cancel transfer') }}</button>
            </div>
        </form>
    </x-modal>
</div>
