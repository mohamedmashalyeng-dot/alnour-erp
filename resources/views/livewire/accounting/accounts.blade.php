<div>
    <x-page-header :title="__('Chart of accounts')">
        <x-slot:actions>
            <select wire:model.live="branch" class="form-input w-auto" aria-label="{{ __('Branch') }}">
                @if ($canAll)<option value="0">{{ __('All branches') }}</option>@endif
                @foreach ($branchOptions as $b)<option value="{{ $b->id }}">{{ $b->displayName() }}</option>@endforeach
            </select>
            @can('accounting.accounts.manage')
                <button wire:click="$toggle('showMappings')" class="btn-secondary">{{ __('Posting rules') }}</button>
                <button wire:click="create" class="btn-primary"><x-icon name="plus" class="size-4" />{{ __('New account') }}</button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    @if ($showMappings)
        <div class="card mb-6">
            <div class="border-b border-slate-200 px-4 py-3">
                <div class="font-semibold">{{ __('Posting rules') }}</div>
                <p class="text-xs text-slate-500">{{ __('Automatic journal entries use these accounts. Change them only with your accountant.') }}</p>
            </div>
            <div class="grid gap-3 p-4 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($mappingKeys as $key)
                    <div>
                        <label class="form-label">{{ __('mapping.'.$key) }}</label>
                        <select wire:model="mappings.{{ $key }}" class="form-input py-1.5">
                            @foreach ($postable as $a)<option value="{{ $a->id }}">{{ $a->code }} — {{ $a->displayName() }}</option>@endforeach
                        </select>
                        @error('mappings.'.$key) <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                @endforeach
            </div>
            <div class="flex justify-end border-t border-slate-200 p-3"><button wire:click="saveMappings" class="btn-primary">{{ __('Save') }}</button></div>
        </div>
    @endif

    <div class="card table-wrap">
        <table class="table">
            <thead><tr><th>{{ __('Code') }}</th><th>{{ __('Account') }}</th><th>{{ __('Type') }}</th><th class="text-end">{{ __('Balance') }}</th><th>{{ __('Status') }}</th><th></th></tr></thead>
            <tbody class="divide-y divide-slate-100">
            @foreach ($accounts as $a)
                @php($bal = ($totals[$a->id] ?? 0) * ($a->normal_balance === 'debit' ? 1 : -1))
                <tr wire:key="acc-{{ $a->id }}" @class(['bg-slate-50 font-semibold' => ! $a->is_postable, 'text-slate-400' => ! $a->is_active])>
                    <td class="font-mono" dir="ltr">{{ $a->code }}</td>
                    <td style="padding-inline-start: {{ 0.75 + $depth($a) * 1.25 }}rem">
                        @if ($a->is_postable)
                            <a href="{{ route('accounting.ledger', ['account' => $a->id, 'branch' => $branch]) }}" class="hover:text-brand-700">{{ $a->displayName() }}</a>
                        @else
                            {{ $a->displayName() }}
                        @endif
                        @if ($a->description)<div class="text-xs font-normal text-slate-500">{{ app()->getLocale() === 'en' && $a->description_en ? $a->description_en : $a->description }}</div>@endif
                    </td>
                    <td>{{ $a->type->label() }}</td>
                    <td class="text-end">{{ $bal ? money($bal) : '—' }}</td>
                    <td>@unless ($a->is_active)<x-status-badge color="rose">{{ __('Inactive') }}</x-status-badge>@endunless</td>
                    <td class="text-end whitespace-nowrap">
                        @can('accounting.accounts.manage')
                            @unless ($a->is_postable)<button wire:click="create({{ $a->id }})" class="btn-secondary btn-sm" title="{{ __('Add sub-account') }}"><x-icon name="plus" class="size-4" /></button>@endunless
                            <button wire:click="edit({{ $a->id }})" class="btn-secondary btn-sm">{{ __('Edit') }}</button>
                        @endcan
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    <x-modal show="showForm" :title="$editingId ? __('Edit account') : __('New account')">
        <form wire:submit="save" class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="form-label">{{ __('Code') }} *</label>
                <input wire:model="form.code" class="form-input font-mono" dir="ltr">
                @error('form.code') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="form-label">{{ __('Type') }} *</label>
                <select wire:model="form.type" class="form-input">@foreach ($types as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</select>
                @error('form.type') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="form-label">{{ __('Name (Arabic)') }} *</label>
                <input wire:model="form.name" class="form-input">
                @error('form.name') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="form-label">{{ __('Name (English)') }}</label>
                <input wire:model="form.name_en" class="form-input" dir="ltr">
            </div>
            <div>
                <label class="form-label">{{ __('Description (Arabic)') }}</label>
                <input wire:model="form.description" class="form-input">
            </div>
            <div>
                <label class="form-label">{{ __('Description (English)') }}</label>
                <input wire:model="form.description_en" class="form-input" dir="ltr">
            </div>
            <div class="sm:col-span-2">
                <label class="form-label">{{ __('Parent account') }}</label>
                <select wire:model="form.parent_id" class="form-input">
                    <option value="">—</option>
                    @foreach ($accounts->where('is_postable', false) as $p)<option value="{{ $p->id }}">{{ $p->code }} — {{ $p->displayName() }}</option>@endforeach
                </select>
                @error('form.parent_id') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="form.is_postable" class="rounded border-slate-300 text-brand-600"> {{ __('Accepts postings (not a header)') }}</label>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="form.is_active" class="rounded border-slate-300 text-brand-600"> {{ __('Active') }}</label>
            @error('form.is_active') <p class="form-error sm:col-span-2">{{ $message }}</p> @enderror
            <div class="flex justify-end gap-2 sm:col-span-2">
                <button type="button" class="btn-secondary" @click="open = false">{{ __('Cancel') }}</button>
                <button type="submit" class="btn-primary">{{ __('Save') }}</button>
            </div>
        </form>
    </x-modal>
</div>
