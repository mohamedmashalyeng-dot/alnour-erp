<div>
    <x-page-header :title="__('Categories')">
        <x-slot:actions>
            <button wire:click="create" class="btn-primary"><x-icon name="plus" class="size-4" />{{ __('New category') }}</button>
        </x-slot:actions>
    </x-page-header>

    <div class="card table-wrap">
        <table class="table">
            <thead><tr><th>{{ __('Name') }}</th><th>{{ __('Name (English)') }}</th><th>{{ __('Code') }}</th><th>{{ __('Parent') }}</th><th>{{ __('Products') }}</th><th>{{ __('Status') }}</th><th></th></tr></thead>
            <tbody class="divide-y divide-slate-100">
            @forelse ($categories as $c)
                <tr wire:key="cat-{{ $c->id }}">
                    <td class="font-medium">{{ $c->name }}</td>
                    <td dir="ltr">{{ $c->name_en }}</td>
                    <td dir="ltr">{{ $c->code }}</td>
                    <td>{{ $c->parent?->displayName() ?? '—' }}</td>
                    <td>{{ $c->products_count }}</td>
                    <td>@if ($c->is_active)<x-status-badge color="emerald">{{ __('Active') }}</x-status-badge>@else<x-status-badge color="rose">{{ __('Inactive') }}</x-status-badge>@endif</td>
                    <td class="text-end">
                        <button wire:click="edit({{ $c->id }})" class="btn-secondary btn-sm">{{ __('Edit') }}</button>
                        <button wire:click="toggle({{ $c->id }})" class="btn-secondary btn-sm">{{ $c->is_active ? __('Deactivate') : __('Activate') }}</button>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="py-8 text-center text-slate-500">{{ __('No categories yet.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <x-modal show="showForm" :title="$editingId ? __('Edit category') : __('New category')" max-width="max-w-lg">
        <form wire:submit="save" class="space-y-4">
            <div>
                <label class="form-label">{{ __('Name (Arabic)') }} *</label>
                <input wire:model="form.name" class="form-input">
                @error('form.name') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="form-label">{{ __('Name (English)') }}</label>
                <input wire:model="form.name_en" class="form-input" dir="ltr">
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="form-label">{{ __('Code') }}</label>
                    <input wire:model="form.code" class="form-input" dir="ltr">
                    @error('form.code') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="form-label">{{ __('Parent') }}</label>
                    <select wire:model="form.parent_id" class="form-input">
                        <option value="">—</option>
                        @foreach ($categories->where('id', '!=', $editingId) as $p)
                            <option value="{{ $p->id }}">{{ $p->displayName() }}</option>
                        @endforeach
                    </select>
                    @error('form.parent_id') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            </div>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="form.is_active" class="rounded border-slate-300 text-brand-600"> {{ __('Active') }}</label>
            <div class="flex justify-end gap-2">
                <button type="button" class="btn-secondary" @click="open = false">{{ __('Cancel') }}</button>
                <button type="submit" class="btn-primary">{{ __('Save') }}</button>
            </div>
        </form>
    </x-modal>
</div>
