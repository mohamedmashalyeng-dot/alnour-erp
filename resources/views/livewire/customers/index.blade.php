<div>
    <x-page-header :title="__('Customers')">
        <x-slot:actions>
            @can('customers.manage')<button wire:click="create" class="btn-primary"><x-icon name="plus" class="size-4" />{{ __('New customer') }}</button>@endcan
        </x-slot:actions>
    </x-page-header>

    <div class="card">
        <div class="flex flex-wrap items-center gap-3 border-b border-slate-200 p-3">
            <input wire:model.live.debounce.300ms="search" type="search" class="form-input max-w-sm" placeholder="{{ __('Search by name, phone or email') }}">
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="withBalance" class="rounded border-slate-300 text-brand-600"> {{ __('Outstanding balance only') }}</label>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>{{ __('Name') }}</th><th>{{ __('Phone') }}</th><th>{{ __('Email') }}</th><th>{{ __('Total purchases') }}</th><th>{{ __('Outstanding balance') }}</th><th>{{ __('Status') }}</th><th></th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                @forelse ($customers as $c)
                    <tr wire:key="cust-{{ $c->id }}">
                        <td class="font-medium"><a href="{{ route('customers.show', $c) }}" class="hover:text-brand-700">{{ $c->name }}</a></td>
                        <td dir="ltr">{{ $c->phone }}</td>
                        <td dir="ltr">{{ $c->email }}</td>
                        <td>{{ money((int) $c->total_purchases) }}</td>
                        <td class="font-semibold {{ $c->balance > 0 ? 'text-rose-700' : '' }}">{{ money((int) $c->balance) }}</td>
                        <td>@if ($c->is_active)<x-status-badge color="emerald">{{ __('Active') }}</x-status-badge>@else<x-status-badge color="rose">{{ __('Inactive') }}</x-status-badge>@endif</td>
                        <td class="text-end">
                            <a href="{{ route('customers.show', $c) }}" class="btn-secondary btn-sm">{{ __('View') }}</a>
                            @can('customers.manage')<button wire:click="edit({{ $c->id }})" class="btn-secondary btn-sm">{{ __('Edit') }}</button>@endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="py-10 text-center text-slate-500">{{ __('No customers found.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-3">{{ $customers->links() }}</div>
    </div>

    <x-modal show="showForm" :title="$editingId ? __('Edit customer') : __('New customer')">
        <form wire:submit="save" class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="form-label">{{ __('Full name') }} *</label>
                <input wire:model="form.name" class="form-input">
                @error('form.name') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="form-label">{{ __('Phone') }}</label>
                <input wire:model="form.phone" class="form-input" dir="ltr">
                @error('form.phone') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="form-label">{{ __('Email') }}</label>
                <input wire:model="form.email" type="email" class="form-input" dir="ltr">
                @error('form.email') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="form-label">{{ __('Address') }}</label>
                <input wire:model="form.address" class="form-input">
            </div>
            <div class="sm:col-span-2">
                <label class="form-label">{{ __('Notes') }}</label>
                <textarea wire:model="form.notes" rows="2" class="form-input"></textarea>
            </div>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="form.is_active" class="rounded border-slate-300 text-brand-600"> {{ __('Active') }}</label>
            <div class="flex justify-end gap-2 sm:col-span-2">
                <button type="button" class="btn-secondary" @click="open = false">{{ __('Cancel') }}</button>
                <button type="submit" class="btn-primary">{{ __('Save') }}</button>
            </div>
        </form>
    </x-modal>
</div>
