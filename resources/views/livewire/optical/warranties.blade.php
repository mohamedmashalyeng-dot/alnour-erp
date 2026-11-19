<div>
    <x-page-header :title="__('Warranties & claims')" :subtitle="__('Warranties are created automatically from sales of products with a warranty period.')" />
    <div class="mb-4 flex flex-wrap items-center gap-2">
        @foreach (['warranties' => __('Warranties'), 'claims' => __('Claims')] as $k => $label)
            <button wire:click="$set('tab', '{{ $k }}')" @class(['btn-primary btn-sm' => $tab === $k, 'btn-secondary btn-sm' => $tab !== $k])>{{ $label }}</button>
        @endforeach
        @if ($tab === 'warranties')
            <input wire:model.live.debounce.400ms="search" class="form-input ms-auto max-w-sm" placeholder="{{ __('Invoice, customer, phone or product') }}">
        @else
            <select wire:model.live="claimStatus" class="form-input ms-auto w-auto">
                <option value="">{{ __('All') }}</option>@foreach (['open', 'approved', 'rejected', 'resolved'] as $s)<option value="{{ $s }}">{{ __('claim.'.$s) }}</option>@endforeach
            </select>
        @endif
    </div>

    @if ($tab === 'warranties')
        <div class="card table-wrap">
            <table class="table">
                <thead><tr><th>{{ __('Product') }}</th><th>{{ __('Customer') }}</th><th>{{ __('Invoice') }}</th><th>{{ __('Qty') }}</th><th>{{ __('From') }}</th><th>{{ __('Until') }}</th><th>{{ __('Status') }}</th><th></th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                @forelse ($warranties as $w)
                    <tr wire:key="w-{{ $w->id }}">
                        <td>{{ $w->product->name }}<div class="text-xs text-slate-500" dir="ltr">{{ $w->product->sku }}</div></td>
                        <td>{{ $w->customer?->name ?? __('Walk-in customer') }}<div class="text-xs text-slate-500" dir="ltr">{{ $w->customer?->phone }}</div></td>
                        <td><a href="{{ route('sales.show', $w->sale_id) }}" class="font-mono text-xs text-brand-700" dir="ltr">{{ $w->sale->invoice_number }}</a></td>
                        <td>{{ $w->quantity }}</td>
                        <td dir="ltr">{{ $w->starts_on->toDateString() }}</td><td dir="ltr">{{ $w->ends_on->toDateString() }}</td>
                        <td>@if ($w->status === 'void')<x-status-badge color="rose">{{ __('Void') }}</x-status-badge><div class="text-xs text-slate-500">{{ $w->void_reason }}</div>
                            @elseif ($w->isValidOn())<x-status-badge color="emerald">{{ __('Valid') }}</x-status-badge>@else<x-status-badge color="amber">{{ __('Expired') }}</x-status-badge>@endif</td>
                        <td>@can('warranty.claims')@if ($w->isValidOn())<button wire:click="startClaim({{ $w->id }})" class="btn-secondary btn-sm">{{ __('Open claim') }}</button>@endif @endcan</td>
                    </tr>
                    @if ($claimFor === $w->id)
                        <tr><td colspan="8" class="bg-slate-50">
                            <form wire:submit="openClaim" class="flex flex-wrap gap-2">
                                <textarea wire:model="issue" rows="2" class="form-input max-w-xl" placeholder="{{ __('Describe the defect or issue') }}"></textarea>
                                <button class="btn-primary btn-sm self-start">{{ __('Open claim') }}</button>
                                <button type="button" wire:click="$set('claimFor', null)" class="btn-secondary btn-sm self-start">{{ __('Cancel') }}</button>
                            </form>
                            @error('issue') <p class="form-error">{{ $message }}</p> @enderror
                        </td></tr>
                    @endif
                @empty
                    <tr><td colspan="8" class="py-10 text-center text-slate-500">{{ __('No warranties found.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
            <div class="p-3">{{ $warranties->links() }}</div>
        </div>
    @else
        <div class="card table-wrap">
            <table class="table">
                <thead><tr><th>{{ __('Number') }}</th><th>{{ __('Product') }}</th><th>{{ __('Customer') }}</th><th>{{ __('Issue') }}</th><th>{{ __('Status') }}</th><th></th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                @forelse ($claims as $c)
                    <tr wire:key="c-{{ $c->id }}">
                        <td class="font-mono text-xs" dir="ltr">{{ $c->number }}<div class="text-slate-500">{{ $c->warranty->sale->invoice_number }}</div></td>
                        <td>{{ $c->warranty->product->name }}</td>
                        <td>{{ $c->warranty->customer?->name }}<div class="text-xs text-slate-500" dir="ltr">{{ $c->warranty->customer?->phone }}</div></td>
                        <td class="max-w-sm whitespace-normal text-sm">{{ $c->issue }}<div class="text-xs text-slate-500">{{ $c->creator?->name }} · {{ $c->created_at->format('Y-m-d') }}</div></td>
                        <td><x-status-badge :color="['open' => 'amber', 'approved' => 'sky', 'rejected' => 'rose', 'resolved' => 'emerald'][$c->status]">{{ __('claim.'.$c->status) }}</x-status-badge>
                            @if ($c->resolution && $c->resolution !== 'none')<div class="text-xs">{{ __('claim.res.'.$c->resolution) }}</div>@endif
                            @if ($c->resolution_notes)<div class="max-w-xs whitespace-normal text-xs text-slate-500">{{ $c->resolution_notes }}</div>@endif</td>
                        <td class="whitespace-nowrap">
                            @if ($c->status === 'open')
                                @can('warranty.decide')
                                    <select wire:model="decision.{{ $c->id }}.resolution" class="form-input mb-1 w-36 py-1 text-xs"><option value="">{{ __('Resolution') }}</option>@foreach (['repair', 'replace', 'refund'] as $r)<option value="{{ $r }}">{{ __('claim.res.'.$r) }}</option>@endforeach</select>
                                    <input wire:model="decision.{{ $c->id }}.notes" class="form-input mb-1 w-36 py-1 text-xs" placeholder="{{ __('Notes') }}">
                                    <div class="flex gap-1"><button wire:click="decide({{ $c->id }}, true)" class="btn-primary btn-sm">{{ __('Approve') }}</button><button wire:click="decide({{ $c->id }}, false)" class="btn-danger btn-sm">{{ __('Reject') }}</button></div>
                                @endcan
                            @elseif ($c->status === 'approved')
                                @can('warranty.claims')
                                    <input wire:model="decision.{{ $c->id }}.notes" class="form-input mb-1 w-40 py-1 text-xs" placeholder="{{ __('What was done') }}">
                                    <button wire:click="resolve({{ $c->id }})" class="btn-secondary btn-sm">{{ __('Mark as resolved') }}</button>
                                @endcan
                            @endif
                            @error('claim.'.$c->id) <p class="form-error">{{ $message }}</p> @enderror
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-10 text-center text-slate-500">{{ __('No claims.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
            <div class="p-3">{{ $claims->links() }}</div>
            <p class="px-3 pb-3 text-xs text-slate-500">{{ __('Replacements and refunds are processed through Returns & exchanges so stock and money stay correct; repairs through Repairs.') }}</p>
        </div>
    @endif
</div>
