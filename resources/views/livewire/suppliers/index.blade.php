<div>
    <x-page-header :title="__('Suppliers')">
        <x-slot:actions>
            @can('suppliers.manage')
                <button wire:click="create" class="btn-primary"><x-icon name="plus" class="size-4" />{{ __('New supplier') }}</button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="card">
        <div class="flex flex-wrap items-center gap-3 border-b border-slate-200 p-3">
            <input wire:model.live.debounce.300ms="search" type="search" class="form-input max-w-sm" placeholder="{{ __('Search by name, company or phone') }}">
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="withBalance" class="rounded border-slate-300 text-brand-600"> {{ __('Outstanding balance only') }}</label>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr>
                    <th>{{ __('Name') }}</th><th>{{ __('Company') }}</th><th>{{ __('Phone') }}</th>
                    <th>{{ __('Total purchases') }}</th><th>{{ __('Total payments') }}</th><th>{{ __('Outstanding balance') }}</th>
                    <th>{{ __('Status') }}</th><th></th>
                </tr></thead>
                <tbody class="divide-y divide-slate-100">
                @forelse ($suppliers as $s)
                    <tr wire:key="sup-{{ $s->id }}">
                        <td class="font-medium">{{ $s->name }}</td>
                        <td>{{ $s->company_name ?: '—' }}</td>
                        <td dir="ltr">{{ $s->phone }}</td>
                        <td>{{ money((int) $s->total_purchases) }}</td>
                        <td>{{ money((int) $s->total_paid) }}</td>
                        <td class="font-semibold {{ $s->total_purchases - $s->total_paid > 0 ? 'text-rose-700' : '' }}">{{ money((int) ($s->total_purchases - $s->total_paid)) }}</td>
                        <td>@if ($s->is_active)<x-status-badge color="emerald">{{ __('Active') }}</x-status-badge>@else<x-status-badge color="rose">{{ __('Inactive') }}</x-status-badge>@endif</td>
                        <td class="text-end">
                            @if (Route::has('suppliers.show'))<a href="{{ route('suppliers.show', $s) }}" class="btn-secondary btn-sm">{{ __('Statement') }}</a>@endif
                            @can('suppliers.manage')<button wire:click="edit({{ $s->id }})" class="btn-secondary btn-sm">{{ __('Edit') }}</button>@endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="py-10 text-center text-slate-500">{{ __('No suppliers found.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-3">{{ $suppliers->links() }}</div>
    </div>

    <x-modal show="showForm" :title="$editingId ? __('Edit supplier') : __('New supplier')">
        <form wire:submit="save" class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="form-label">{{ __('Name') }} *</label>
                <input wire:model="form.name" class="form-input">
                @error('form.name') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="form-label">{{ __('Company name') }}</label>
                <input wire:model="form.company_name" class="form-input">
            </div>
            <div>
                <label class="form-label">{{ __('Code') }}</label>
                <input wire:model="form.code" class="form-input" dir="ltr">
                @error('form.code') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="form-label">{{ __('Phone') }}</label>
                <input wire:model="form.phone" class="form-input" dir="ltr">
            </div>
            <div>
                <label class="form-label">{{ __('Email') }}</label>
                <input wire:model="form.email" type="email" class="form-input" dir="ltr">
                @error('form.email') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="form-label">{{ __('Tax number') }}</label>
                <input wire:model="form.tax_number" class="form-input" dir="ltr">
            </div>
            <div class="sm:col-span-2">
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
