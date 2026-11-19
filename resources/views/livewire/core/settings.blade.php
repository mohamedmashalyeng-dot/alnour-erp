<div>
    <x-page-header :title="__('Settings')" />

    <div class="grid gap-6 xl:grid-cols-2">
        <form wire:submit="save" class="card card-body space-y-4">
            <h2 class="font-semibold">{{ __('Business settings') }}</h2>
            <div>
                <label class="form-label">{{ __('Company name') }} *</label>
                <input wire:model="form.company_name" class="form-input">
                @error('form.company_name') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="form-label">{{ __('Company tax number') }}</label>
                <input wire:model="form.company_tax_number" class="form-input" dir="ltr">
            </div>
            <div>
                <label class="form-label">{{ __('Invoice footer') }}</label>
                <textarea wire:model="form.invoice_footer" rows="2" class="form-input"></textarea>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="form-label">{{ __('Costing method') }}</label>
                    <select wire:model="form.costing_method" class="form-input">
                        <option value="weighted_average">{{ __('Weighted average cost') }}</option>
                        <option value="last_purchase">{{ __('Last purchase price') }}</option>
                    </select>
                </div>
                <div>
                    <label class="form-label">{{ __('Maximum cashier discount (%)') }}</label>
                    <input wire:model="form.max_discount_percent" type="number" min="0" max="100" class="form-input">
                    @error('form.max_discount_percent') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            </div>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="form.tax_enabled" class="rounded border-slate-300 text-brand-600"> {{ __('Enable tax (VAT)') }}</label>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="form.prices_include_tax" class="rounded border-slate-300 text-brand-600"> {{ __('Selling prices include tax') }}</label>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="form.cash_shift_required" class="rounded border-slate-300 text-brand-600"> {{ __('Cashiers must open a cash shift before taking cash') }}</label>
            <div>
                <label class="form-label">{{ __('Payment terms (days before a receivable is overdue)') }}</label>
                <input wire:model="form.payment_terms_days" type="number" min="0" max="365" class="form-input max-w-[8rem]">
                @error('form.payment_terms_days') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="form-label">{{ __('Supplier lead time for reorder suggestions (days)') }}</label>
                    <input wire:model="form.reorder_lead_days" type="number" min="1" max="180" class="form-input max-w-[8rem]">
                    @error('form.reorder_lead_days') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="form-label">{{ __('Safety stock (days of sales)') }}</label>
                    <input wire:model="form.reorder_safety_days" type="number" min="0" max="180" class="form-input max-w-[8rem]">
                    @error('form.reorder_safety_days') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="form-label">{{ __('Offline sales that exceed stock') }}</label>
                    <select wire:model="form.offline_oversell_policy" class="form-input">
                        <option value="review">{{ __('Record them and flag for review') }}</option>
                        <option value="reject">{{ __('Keep them out and flag for review') }}</option>
                    </select>
                    @error('form.offline_oversell_policy') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="form-label">{{ __('Oldest offline sale accepted (days)') }}</label>
                    <input wire:model="form.offline_max_age_days" type="number" min="1" max="30" class="form-input max-w-[8rem]">
                    @error('form.offline_max_age_days') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="rounded-lg border border-slate-200 p-3">
                <label class="mb-3 flex items-center gap-2 text-sm font-semibold"><input type="checkbox" wire:model="form.loyalty_enabled" class="rounded border-slate-300 text-brand-600"> {{ __('Enable the loyalty program') }}</label>
                <div class="grid gap-3 sm:grid-cols-3">
                    @foreach (['loyalty_points_per_unit' => __('Points per 1 currency unit spent'), 'loyalty_point_value' => __('Value of 1 point when redeemed'), 'loyalty_min_redeem' => __('Minimum points to redeem'),
                               'loyalty_max_redeem_percent' => __('Max share of an invoice paid with points (%)'), 'loyalty_silver_threshold' => __('Lifetime points for Silver (×1.25)'), 'loyalty_gold_threshold' => __('Lifetime points for Gold (×1.5)')] as $k => $label)
                        <div><label class="form-label">{{ $label }}</label><input wire:model="form.{{ $k }}" inputmode="decimal" class="form-input" dir="ltr">@error('form.'.$k) <p class="form-error">{{ $message }}</p> @enderror</div>
                    @endforeach
                </div>
            </div>
            <div>
                <label class="form-label">{{ __('Service product used to invoice repairs') }}</label>
                <select wire:model="form.repair_service_product_id" class="form-input max-w-md"><option value="">—</option>@foreach (\App\Modules\Catalog\Models\Product::where('product_type', 'service')->where('track_stock', false)->orderBy('name')->get(['id', 'name', 'sku']) as $p)<option value="{{ $p->id }}">{{ $p->name }} ({{ $p->sku }})</option>@endforeach</select>
                @error('form.repair_service_product_id') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="form.walk_in_customer_enabled" class="rounded border-slate-300 text-brand-600"> {{ __('Allow walk-in sales without a registered customer') }}</label>
            <div class="flex justify-end"><button class="btn-primary">{{ __('Save') }}</button></div>
        </form>

        <div class="card">
            <div class="border-b border-slate-200 px-4 py-3 font-semibold">{{ __('Tax rates') }}</div>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>{{ __('Name') }}</th><th>{{ __('Rate') }}</th><th>{{ __('Status') }}</th><th></th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                    @forelse ($rates as $r)
                        <tr wire:key="rate-{{ $r->id }}">
                            <td>{{ $r->name }} @if ($r->is_default)<x-status-badge color="sky">{{ __('Default') }}</x-status-badge>@endif</td>
                            <td dir="ltr">{{ $r->percentLabel() }}</td>
                            <td>@if ($r->is_active)<x-status-badge color="emerald">{{ __('Active') }}</x-status-badge>@else<x-status-badge color="rose">{{ __('Inactive') }}</x-status-badge>@endif</td>
                            <td class="text-end">
                                @unless ($r->is_default)<button wire:click="makeDefault({{ $r->id }})" class="btn-secondary btn-sm">{{ __('Make default') }}</button>@endunless
                                <button wire:click="toggleRate({{ $r->id }})" class="btn-secondary btn-sm">{{ $r->is_active ? __('Deactivate') : __('Activate') }}</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-slate-500">{{ __('No tax rates defined.') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <form wire:submit="addRate" class="grid gap-3 border-t border-slate-200 p-4 sm:grid-cols-4">
                <input wire:model="rate.name" class="form-input" placeholder="{{ __('Name') }}">
                <input wire:model="rate.name_en" class="form-input" placeholder="{{ __('Name (English)') }}" dir="ltr">
                <input wire:model="rate.percent" class="form-input" placeholder="15" dir="ltr" inputmode="decimal">
                <button class="btn-primary">{{ __('Add rate') }}</button>
                @error('rate.name') <p class="form-error sm:col-span-4">{{ $message }}</p> @enderror
                @error('rate.percent') <p class="form-error sm:col-span-4">{{ $message }}</p> @enderror
            </form>
        </div>
    </div>
</div>
