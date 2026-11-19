<div>
    <x-page-header :title="__('Branches')">
        <x-slot:actions>
            @can('branches.manage')
                <button wire:click="create" class="btn-primary"><x-icon name="plus" class="size-4" />{{ __('New branch') }}</button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="card table-wrap">
        <table class="table">
            <thead><tr>
                <th>{{ __('Code') }}</th><th>{{ __('Name') }}</th><th>{{ __('City') }}</th><th>{{ __('Phone') }}</th>
                <th>{{ __('Users') }}</th><th>{{ __('Units') }}</th><th>{{ __('Status') }}</th><th></th>
            </tr></thead>
            <tbody class="divide-y divide-slate-100">
            @foreach ($branches as $branch)
                <tr wire:key="branch-{{ $branch->id }}">
                    <td class="font-mono" dir="ltr">{{ $branch->code }}</td>
                    <td class="font-medium">{{ $branch->displayName() }}</td>
                    <td>{{ $branch->city }}</td>
                    <td dir="ltr">{{ $branch->phone }}</td>
                    <td>{{ $branch->users_count }}</td>
                    <td>{{ number_format((int) $branch->units) }}</td>
                    <td>
                        @if ($branch->is_active)<x-status-badge color="emerald">{{ __('Active') }}</x-status-badge>
                        @else<x-status-badge color="rose">{{ __('Inactive') }}</x-status-badge>@endif
                    </td>
                    <td class="text-end">
                        <a href="{{ route('branches.show', $branch) }}" class="btn-secondary btn-sm">{{ __('View') }}</a>
                        @can('branches.manage')
                            <button wire:click="edit({{ $branch->id }})" class="btn-secondary btn-sm">{{ __('Edit') }}</button>
                            <button wire:click="toggle({{ $branch->id }})" wire:confirm="{{ __('Are you sure?') }}" class="btn-secondary btn-sm">
                                {{ $branch->is_active ? __('Deactivate') : __('Activate') }}
                            </button>
                        @endcan
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    <x-modal show="showForm" :title="$editingId ? __('Edit branch') : __('New branch')">
        <form wire:submit="save" class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="form-label">{{ __('Code') }} *</label>
                <input wire:model="form.code" class="form-input uppercase" dir="ltr" maxlength="16">
                @error('form.code') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="form-label">{{ __('Name (Arabic)') }} *</label>
                <input wire:model="form.name" class="form-input">
                @error('form.name') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="form-label">{{ __('Name (English)') }}</label>
                <input wire:model="form.name_en" class="form-input" dir="ltr">
                @error('form.name_en') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="form-label">{{ __('City') }}</label>
                <input wire:model="form.city" class="form-input">
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
            <div class="sm:col-span-2">
                <label class="form-label">{{ __('Address') }}</label>
                <input wire:model="form.address" class="form-input">
            </div>
            <div>
                <label class="form-label">{{ __('Tax number') }}</label>
                <input wire:model="form.tax_number" class="form-input" dir="ltr">
            </div>
            <label class="flex items-center gap-2 self-end text-sm">
                <input type="checkbox" wire:model="form.is_active" class="rounded border-slate-300 text-brand-600"> {{ __('Active') }}
            </label>
            <div class="sm:col-span-2">
                <label class="form-label">{{ __('Notes') }}</label>
                <textarea wire:model="form.notes" rows="2" class="form-input"></textarea>
            </div>
            <div class="flex justify-end gap-2 sm:col-span-2">
                <button type="button" class="btn-secondary" @click="open = false">{{ __('Cancel') }}</button>
                <button type="submit" class="btn-primary">{{ __('Save') }}</button>
            </div>
        </form>
    </x-modal>
</div>
