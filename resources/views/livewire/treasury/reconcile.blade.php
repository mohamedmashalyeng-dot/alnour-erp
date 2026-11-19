<div>
    <x-page-header :title="__('Reconcile').' — '.$bank->name">
        <x-slot:actions><a href="{{ route('bank.index') }}" class="btn-secondary">{{ __('Back') }}</a></x-slot:actions>
    </x-page-header>
    <div class="card card-body mb-4 grid gap-4 sm:grid-cols-5">
        <div><label class="form-label">{{ __('Statement date') }}</label><input type="date" wire:model.live="statementDate" class="form-input"></div>
        <div><label class="form-label">{{ __('Statement ending balance') }}</label><input wire:model.live.debounce.400ms="statementBalance" inputmode="decimal" class="form-input" dir="ltr">
            @error('statementBalance') <p class="form-error">{{ $message }}</p> @enderror @error('statement_balance') <p class="form-error">{{ $message }}</p> @enderror</div>
        <div class="rounded-lg bg-slate-50 p-3"><div class="text-xs text-slate-500">{{ __('Previously reconciled') }}</div><div class="font-semibold">{{ money($reconciled) }}</div></div>
        <div class="rounded-lg bg-slate-50 p-3"><div class="text-xs text-slate-500">{{ __('Reconciled after selection') }}</div><div class="font-semibold">{{ money($after) }}</div></div>
        <div class="rounded-lg p-3 {{ $difference === 0 ? 'bg-emerald-50 text-emerald-800' : 'bg-rose-50 text-rose-800' }}"><div class="text-xs">{{ __('Difference') }}</div><div class="font-semibold">{{ money($difference) }}</div></div>
    </div>
    <div class="card table-wrap">
        <table class="table">
            <thead><tr><th></th><th>{{ __('Date') }}</th><th>{{ __('Entry') }}</th><th>{{ __('Description') }}</th><th class="text-end">{{ __('Deposit') }}</th><th class="text-end">{{ __('Withdrawal') }}</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
            @forelse ($lines as $l)
                <tr wire:key="rl-{{ $l->id }}">
                    <td><input type="checkbox" wire:model.live="selected" value="{{ $l->id }}" class="rounded border-slate-300 text-brand-600" aria-label="{{ __('Select') }}"></td>
                    <td class="text-xs" dir="ltr">{{ $l->entry->entry_date->format('Y-m-d') }}</td>
                    <td class="font-mono text-xs" dir="ltr">{{ $l->entry->number }}</td>
                    <td class="max-w-md truncate">{{ $l->description ?: $l->entry->memo }}</td>
                    <td class="text-end">{{ $l->debit ? money($l->debit, false) : '' }}</td>
                    <td class="text-end">{{ $l->credit ? money($l->credit, false) : '' }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="py-8 text-center text-slate-500">{{ __('Everything up to this date is reconciled.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
        <div class="flex justify-end border-t border-slate-200 p-3">
            <button wire:click="reconcile" class="btn-primary" @disabled($difference !== 0 || empty($selected))>{{ __('Save reconciliation') }}</button>
        </div>
    </div>
    @if ($history->isNotEmpty())
        <div class="card mt-6">
            <div class="border-b border-slate-200 px-4 py-3 font-semibold">{{ __('Reconciliation history') }}</div>
            <table class="table"><tbody class="divide-y divide-slate-100">
                @foreach ($history as $h)
                    <tr><td dir="ltr">{{ $h->statement_date->format('Y-m-d') }}</td><td>{{ money($h->statement_balance) }}</td><td>{{ $h->user->name }}</td><td class="text-xs" dir="ltr">{{ $h->created_at->format('Y-m-d H:i') }}</td></tr>
                @endforeach
            </tbody></table>
        </div>
    @endif
</div>
