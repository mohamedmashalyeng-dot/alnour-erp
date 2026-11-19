<div>
    <x-page-header :title="__('Bank accounts')">
        <x-slot:actions>
            @can('bank.manage')<button wire:click="$set('showForm', true)" class="btn-primary"><x-icon name="plus" class="size-4" />{{ __('New bank account') }}</button>@endcan
            @canany(['cash.manage', 'bank.manage'])<a href="{{ route('cash.index') }}" class="btn-secondary">{{ __('Deposit / withdrawal / transfer') }}</a>@endcanany
        </x-slot:actions>
    </x-page-header>
    <div class="card table-wrap">
        <table class="table">
            <thead><tr><th>{{ __('Name') }}</th><th>{{ __('Bank') }}</th><th>{{ __('Account number') }}</th><th>IBAN</th><th class="text-end">{{ __('Ledger balance') }}</th><th class="text-end">{{ __('Reconciled balance') }}</th><th></th></tr></thead>
            <tbody class="divide-y divide-slate-100">
            @foreach ($banks as $b)
                <tr wire:key="bank-{{ $b->id }}">
                    <td class="font-medium">{{ $b->name }} @if ($b->is_default)<x-status-badge color="sky">{{ __('Default') }}</x-status-badge>@endif</td>
                    <td>{{ $b->bank_name ?: '—' }}</td>
                    <td dir="ltr">{{ $b->account_number ?: '—' }}</td>
                    <td class="font-mono text-xs" dir="ltr">{{ $b->iban ?: '—' }}</td>
                    <td class="text-end font-semibold">{{ money($b->balance) }}</td>
                    <td class="text-end">{{ money($b->reconciled) }}</td>
                    <td class="text-end whitespace-nowrap">
                        @can('accounting.view')<a href="{{ route('accounting.ledger', ['account' => $b->account_id, 'branch' => 0]) }}" class="btn-secondary btn-sm">{{ __('Transactions') }}</a>@endcan
                        @can('bank.reconcile')<a href="{{ route('bank.reconcile', $b) }}" class="btn-secondary btn-sm">{{ __('Reconcile') }}</a>@endcan
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    <x-modal show="showForm" :title="__('New bank account')">
        <form wire:submit="save" class="grid gap-4 sm:grid-cols-2">
            <div><label class="form-label">{{ __('Name') }} *</label><input wire:model="form.name" class="form-input">@error('form.name') <p class="form-error">{{ $message }}</p> @enderror</div>
            <div><label class="form-label">{{ __('Bank') }}</label><input wire:model="form.bank_name" class="form-input"></div>
            <div><label class="form-label">{{ __('Account number') }}</label><input wire:model="form.account_number" class="form-input" dir="ltr"></div>
            <div><label class="form-label">IBAN</label><input wire:model="form.iban" class="form-input" dir="ltr">@error('form.iban') <p class="form-error">{{ $message }}</p> @enderror</div>
            <div class="sm:col-span-2">
                <label class="form-label">{{ __('Branch (reporting dimension)') }}</label>
                <select wire:model="form.branch_id" class="form-input"><option value="">—</option>@foreach ($branches as $br)<option value="{{ $br->id }}">{{ $br->displayName() }}</option>@endforeach</select>
            </div>
            <div class="flex justify-end gap-2 sm:col-span-2">
                <button type="button" class="btn-secondary" @click="open = false">{{ __('Cancel') }}</button>
                <button class="btn-primary">{{ __('Save') }}</button>
            </div>
        </form>
    </x-modal>
</div>
