<div>
    <x-page-header :title="__('Brands')">
        <x-slot:actions>
            <button wire:click="create" class="btn-primary"><x-icon name="plus" class="size-4" />{{ __('New brand') }}</button>
        </x-slot:actions>
    </x-page-header>

    <div class="card table-wrap">
        <table class="table">
            <thead><tr><th>{{ __('Name') }}</th><th>{{ __('Name (English)') }}</th><th>{{ __('Products') }}</th><th>{{ __('Status') }}</th><th></th></tr></thead>
            <tbody class="divide-y divide-slate-100">
            @forelse ($brands as $b)
                <tr wire:key="brand-{{ $b->id }}">
                    <td class="font-medium">{{ $b->name }}</td>
                    <td dir="ltr">{{ $b->name_en }}</td>
                    <td>{{ $b->products_count }}</td>
                    <td>@if ($b->is_active)<x-status-badge color="emerald">{{ __('Active') }}</x-status-badge>@else<x-status-badge color="rose">{{ __('Inactive') }}</x-status-badge>@endif</td>
                    <td class="text-end">
                        <button wire:click="edit({{ $b->id }})" class="btn-secondary btn-sm">{{ __('Edit') }}</button>
                        <button wire:click="toggle({{ $b->id }})" class="btn-secondary btn-sm">{{ $b->is_active ? __('Deactivate') : __('Activate') }}</button>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="py-8 text-center text-slate-500">{{ __('No brands yet.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <x-modal show="showForm" :title="$editingId ? __('Edit brand') : __('New brand')" max-width="max-w-lg">
        <form wire:submit="save" class="space-y-4">
            <div>
                <label class="form-label">{{ __('Name') }} *</label>
                <input wire:model="form.name" class="form-input">
                @error('form.name') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="form-label">{{ __('Name (English)') }}</label>
                <input wire:model="form.name_en" class="form-input" dir="ltr">
            </div>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="form.is_active" class="rounded border-slate-300 text-brand-600"> {{ __('Active') }}</label>
            <div class="flex justify-end gap-2">
                <button type="button" class="btn-secondary" @click="open = false">{{ __('Cancel') }}</button>
                <button type="submit" class="btn-primary">{{ __('Save') }}</button>
            </div>
        </form>
    </x-modal>
</div>
