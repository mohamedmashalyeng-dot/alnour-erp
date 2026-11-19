<div>
    <x-page-header :title="__('Manual journal entry')" :subtitle="__('Saved as a draft; an authorized user must post it.')">
        <x-slot:actions><a href="{{ route('accounting.journal') }}" class="btn-secondary">{{ __('Cancel') }}</a></x-slot:actions>
    </x-page-header>
    <div class="card card-body mb-4 grid gap-4 sm:grid-cols-3">
        <div>
            <label class="form-label">{{ __('Date') }} *</label>
            <input type="date" wire:model="date" class="form-input">
            @error('date') <p class="form-error">{{ $message }}</p> @enderror
            @error('entry_date') <p class="form-error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="form-label">{{ __('Branch') }} *</label>
            <select wire:model="branchId" class="form-input">@foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->displayName() }}</option>@endforeach</select>
        </div>
        <div>
            <label class="form-label">{{ __('Description') }} *</label>
            <input wire:model="memo" class="form-input" maxlength="255">
            @error('memo') <p class="form-error">{{ $message }}</p> @enderror
        </div>
    </div>
    <div class="card table-wrap">
        <table class="table">
            <thead><tr><th>{{ __('Account') }}</th><th>{{ __('Description') }}</th><th>{{ __('Debit') }}</th><th>{{ __('Credit') }}</th><th></th></tr></thead>
            <tbody class="divide-y divide-slate-100">
            @foreach ($lines as $i => $l)
                <tr wire:key="jl-{{ $i }}">
                    <td>
                        <select wire:model="lines.{{ $i }}.account_id" class="form-input min-w-64 py-1">
                            <option value="">—</option>
                            @foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->code }} — {{ $a->displayName() }}</option>@endforeach
                        </select>
                        @error("lines.$i.account_id") <p class="form-error">{{ $message }}</p> @enderror
                        @error("lines.$i") <p class="form-error">{{ $message }}</p> @enderror
                    </td>
                    <td><input wire:model="lines.{{ $i }}.description" class="form-input py-1"></td>
                    <td><input wire:model.live.debounce.400ms="lines.{{ $i }}.debit" inputmode="decimal" class="form-input w-28 py-1" dir="ltr">@error("lines.$i.debit") <p class="form-error">{{ $message }}</p> @enderror</td>
                    <td><input wire:model.live.debounce.400ms="lines.{{ $i }}.credit" inputmode="decimal" class="form-input w-28 py-1" dir="ltr">@error("lines.$i.credit") <p class="form-error">{{ $message }}</p> @enderror</td>
                    <td>@if (count($lines) > 2)<button type="button" wire:click="removeLine({{ $i }})" class="text-rose-600"><x-icon name="trash" class="size-4" /></button>@endif</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot><tr class="bg-slate-50 font-semibold {{ $debit === $credit ? '' : 'text-rose-700' }}"><td colspan="2">{{ __('Total') }} @if ($debit !== $credit)· {{ __('Difference') }} {{ money(abs($debit - $credit)) }}@endif</td><td>{{ money($debit) }}</td><td>{{ money($credit) }}</td><td></td></tr></tfoot>
        </table>
        @error('lines') <p class="form-error p-3">{{ $message }}</p> @enderror
        <div class="flex justify-between border-t border-slate-200 p-3">
            <button type="button" wire:click="addLine" class="btn-secondary btn-sm"><x-icon name="plus" class="size-4" />{{ __('Add line') }}</button>
            <button wire:click="save" class="btn-primary" wire:loading.attr="disabled">{{ __('Save draft') }}</button>
        </div>
    </div>
</div>
