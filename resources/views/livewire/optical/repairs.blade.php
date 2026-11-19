<div>
    <x-page-header :title="__('Repairs')" :subtitle="__('Intake, workshop status and delivery. Charged repairs are invoiced through the sales engine.')">
        <x-slot:actions>@can('repairs.manage')<button wire:click="create" class="btn-primary"><x-icon name="plus" class="size-4" />{{ __('New repair') }}</button>@endcan</x-slot:actions>
    </x-page-header>
    <div class="mb-3 flex flex-wrap gap-2">
        @foreach (['open' => __('Open'), 'received' => __('repair.received'), 'in_progress' => __('repair.in_progress'), 'ready' => __('repair.ready'), 'delivered' => __('repair.delivered'), 'cancelled' => __('repair.cancelled'), '' => __('All')] as $k => $label)
            <button wire:click="$set('status', '{{ $k }}')" @class(['btn-primary btn-sm' => $status === $k, 'btn-secondary btn-sm' => $status !== $k])>{{ $label }}</button>
        @endforeach
    </div>

    <div class="card table-wrap">
        <table class="table">
            <thead><tr><th>{{ __('Number') }}</th><th>{{ __('Customer') }}</th><th>{{ __('Item') }}</th><th>{{ __('Problem') }}</th><th class="text-end">{{ __('Cost') }}</th><th>{{ __('Promised') }}</th><th>{{ __('Status') }}</th><th></th></tr></thead>
            <tbody class="divide-y divide-slate-100">
            @forelse ($orders as $o)
                <tr wire:key="ro-{{ $o->id }}">
                    <td class="font-mono text-xs" dir="ltr">{{ $o->number }}@if ($o->claim)<div class="text-sky-700">{{ $o->claim->number }}</div>@endif</td>
                    <td>{{ $o->customer->name }}<div class="text-xs text-slate-500" dir="ltr">{{ $o->customer->phone }}</div></td>
                    <td>{{ $o->item_description }}</td>
                    <td class="max-w-xs whitespace-normal text-sm">{{ $o->problem }}</td>
                    <td class="text-end">{{ money($o->final_cost ?? $o->estimated_cost) }}@if ($o->final_cost === null)<div class="text-xs text-slate-500">{{ __('estimate') }}</div>@endif</td>
                    <td dir="ltr">{{ $o->promised_on?->toDateString() }}</td>
                    <td><x-status-badge :color="['received' => 'slate', 'in_progress' => 'amber', 'ready' => 'sky', 'delivered' => 'emerald', 'cancelled' => 'rose'][$o->status]">{{ __('repair.'.$o->status) }}</x-status-badge>
                        @if ($o->sale)<a href="{{ route('sales.show', $o->sale_id) }}" class="block font-mono text-xs text-brand-700" dir="ltr">{{ $o->sale->invoice_number }}</a>@endif</td>
                    <td class="whitespace-nowrap">
                        @can('repairs.manage')
                            @foreach (\App\Modules\Optical\Models\RepairOrder::TRANSITIONS[$o->status] ?? [] as $next)
                                <button wire:click="move({{ $o->id }}, '{{ $next }}')" @if ($next === 'cancelled') wire:confirm="{{ __('Cancel this repair?') }}" @endif class="{{ $next === 'cancelled' ? 'btn-danger' : 'btn-secondary' }} btn-sm">{{ __('repair.to.'.$next) }}</button>
                            @endforeach
                            @if ($o->status === 'ready')<button wire:click="startDelivery({{ $o->id }})" class="btn-primary btn-sm">{{ __('Deliver') }}</button>@endif
                        @endcan
                        @error('repair.'.$o->id) <p class="form-error">{{ $message }}</p> @enderror
                    </td>
                </tr>
                @if ($delivering === $o->id)
                    <tr><td colspan="8" class="bg-slate-50">
                        <form wire:submit="deliver" class="flex flex-wrap items-end gap-2">
                            <div><label class="form-label">{{ __('Final cost') }}</label><input wire:model="delivery.final_cost" inputmode="decimal" class="form-input w-32" dir="ltr" @disabled($o->warranty_claim_id)></div>
                            <div><label class="form-label">{{ __('Payment method') }}</label><select wire:model="delivery.method" class="form-input">@foreach ($methods as $m)<option value="{{ $m->value }}">{{ $m->label() }}</option>@endforeach</select></div>
                            <div><label class="form-label">{{ __('Amount paid now') }}</label><input wire:model="delivery.amount" inputmode="decimal" class="form-input w-32" dir="ltr"></div>
                            <div><label class="form-label">{{ __('Reference') }}</label><input wire:model="delivery.reference" class="form-input w-40"></div>
                            <button class="btn-primary">{{ __('Deliver') }}</button>
                            <button type="button" wire:click="$set('delivering', null)" class="btn-secondary">{{ __('Cancel') }}</button>
                        </form>
                        @foreach (['final_cost', 'status', 'payments', 'customer_id', 'uuid', 'discount'] as $f) @error('delivery.'.$f) <p class="form-error">{{ $message }}</p> @enderror @endforeach
                    </td></tr>
                @endif
            @empty
                <tr><td colspan="8" class="py-10 text-center text-slate-500">{{ __('No repairs.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
        <div class="p-3">{{ $orders->links() }}</div>
    </div>

    <x-modal show="showForm" :title="__('New repair')">
        <form wire:submit="save" class="grid gap-3 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <label class="form-label">{{ __('Customer') }} *</label>
                @if ($selectedCustomer)
                    <div class="flex items-center gap-2"><span class="font-medium">{{ $selectedCustomer->name }}</span><span class="text-xs text-slate-500" dir="ltr">{{ $selectedCustomer->phone }}</span>
                        <button type="button" wire:click="$set('form.customer_id', '')" class="text-xs text-rose-700">{{ __('Change') }}</button></div>
                @else
                    <input wire:model.live.debounce.300ms="customerSearch" class="form-input" placeholder="{{ __('Search customer by name or phone') }}">
                    @foreach ($customerResults as $c)<button type="button" wire:click="pickCustomer({{ $c->id }})" class="block w-full rounded px-2 py-1 text-start text-sm hover:bg-slate-100">{{ $c->name }} <span dir="ltr" class="text-slate-500">{{ $c->phone }}</span></button>@endforeach
                @endif
                @error('form.customer_id') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div><label class="form-label">{{ __('Branch') }}</label><select wire:model="form.branch_id" class="form-input">@foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->displayName() }}</option>@endforeach</select></div>
            <div><label class="form-label">{{ __('Approved warranty claim (free repair)') }}</label><select wire:model="form.warranty_claim_id" class="form-input"><option value="">—</option>@foreach ($claims as $c)<option value="{{ $c->id }}">{{ $c->number }}</option>@endforeach</select>@error('form.warranty_claim_id') <p class="form-error">{{ $message }}</p> @enderror</div>
            <div class="sm:col-span-2"><label class="form-label">{{ __('Item') }} *</label><input wire:model="form.item_description" class="form-input" placeholder="{{ __('e.g. black metal frame (customer property)') }}">@error('form.item_description') <p class="form-error">{{ $message }}</p> @enderror</div>
            <div class="sm:col-span-2"><label class="form-label">{{ __('Problem') }} *</label><textarea wire:model="form.problem" rows="2" class="form-input"></textarea>@error('form.problem') <p class="form-error">{{ $message }}</p> @enderror</div>
            <div><label class="form-label">{{ __('Estimated cost') }}</label><input wire:model="form.estimated_cost" inputmode="decimal" class="form-input" dir="ltr">@error('form.estimated_cost') <p class="form-error">{{ $message }}</p> @enderror</div>
            <div><label class="form-label">{{ __('Promised date') }}</label><input type="date" wire:model="form.promised_on" class="form-input">@error('form.promised_on') <p class="form-error">{{ $message }}</p> @enderror</div>
            <div class="sm:col-span-2 flex justify-end gap-2"><button type="button" wire:click="$set('showForm', false)" class="btn-secondary">{{ __('Cancel') }}</button><button class="btn-primary">{{ __('Save') }}</button></div>
        </form>
    </x-modal>
</div>
