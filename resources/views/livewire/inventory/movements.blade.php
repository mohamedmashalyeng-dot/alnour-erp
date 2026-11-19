<div>
    <x-page-header :title="__('Stock movements')" :subtitle="__('Complete, read-only history of every stock change.')" />

    <div class="card">
        <div class="grid gap-3 border-b border-slate-200 p-3 sm:grid-cols-2 lg:grid-cols-5">
            <input wire:model.live.debounce.300ms="search" type="search" class="form-input" placeholder="{{ __('Search by barcode, SKU or name') }}">
            <select wire:model.live="branch" class="form-input">
                @if ($canAll)<option value="0">{{ __('All branches') }}</option>@endif
                @foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->displayName() }}</option>@endforeach
            </select>
            <select wire:model.live="type" class="form-input">
                <option value="">{{ __('All movement types') }}</option>
                @foreach ($types as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
            </select>
            <input type="date" wire:model.live="from" class="form-input" aria-label="{{ __('From') }}">
            <input type="date" wire:model.live="to" class="form-input" aria-label="{{ __('To') }}">
        </div>
        @include('livewire.inventory.partials.movements-table', ['movements' => $movements])
        <div class="p-3">{{ $movements->links() }}</div>
    </div>
</div>
