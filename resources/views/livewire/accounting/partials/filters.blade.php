{{-- Shared report filter bar. Pass $showFrom = false for "as of" reports. --}}
@if (request()->routeIs('accounting.*') && ! request()->routeIs('accounting.dashboard', 'accounting.journal*'))
    <nav class="no-print mb-3 flex flex-wrap gap-1 text-sm">
        @foreach (['accounting.income-statement' => 'Income statement', 'accounting.balance-sheet' => 'Balance sheet', 'accounting.trial-balance' => 'Trial balance',
                   'accounting.cash-flow' => 'Cash flow statement', 'accounting.ledger' => 'General ledger', 'accounting.revenue' => 'Revenue report',
                   'accounting.vat' => 'VAT report', 'accounting.aging' => 'Aging report'] as $r => $label)
            <a href="{{ route($r, ['branch' => $branch, 'from' => $from, 'to' => $to]) }}" @class(['rounded-lg px-3 py-1.5', 'bg-brand-600 text-white' => request()->routeIs($r), 'bg-white text-slate-600 hover:bg-slate-100' => ! request()->routeIs($r)])>{{ __($label) }}</a>
        @endforeach
    </nav>
@endif
<div class="no-print card mb-4 flex flex-wrap items-end gap-3 p-3">
    <div>
        <label class="form-label">{{ __('Branch') }}</label>
        <select wire:model.live="branch" class="form-input">
            @if ($canAll)<option value="0">{{ __('All branches') }}</option>@endif
            @foreach ($branchOptions as $b)<option value="{{ $b->id }}">{{ $b->displayName() }}</option>@endforeach
        </select>
    </div>
    @if ($showFrom ?? true)
        <div>
            <label class="form-label">{{ __('From') }}</label>
            <input type="date" wire:model.live="from" class="form-input">
        </div>
    @endif
    <div>
        <label class="form-label">{{ ($showFrom ?? true) ? __('To') : __('As of') }}</label>
        <input type="date" wire:model.live="to" class="form-input">
    </div>
    {{ $slot ?? '' }}
    <button type="button" onclick="window.print()" class="btn-secondary ms-auto"><x-icon name="printer" class="size-4" />{{ __('Print / PDF') }}</button>
</div>
<div class="mb-3 hidden text-sm print:block">
    {{ app(\App\Modules\Core\Services\Settings::class)->get('company_name') }} —
    {{ $branch ? $branchOptions->firstWhere('id', $branch)?->displayName() : __('All branches') }} —
    <span dir="ltr">{{ ($showFrom ?? true) ? $from.' → ' : '' }}{{ $to }}</span>
</div>
