<div>
    <x-page-header :title="__('Cash management')" :subtitle="__('Cash registers, cashier shifts and daily reconciliation.')">
        <x-slot:actions>
            @canany(['cash.manage', 'bank.manage'])<button wire:click="$set('showMovement', true)" class="btn-primary"><x-icon name="arrows" class="size-4" />{{ __('Deposit / withdrawal / transfer') }}</button>@endcanany
        </x-slot:actions>
    </x-page-header>

    @foreach (['register', 'shift', 'counted', 'amount', 'payments'] as $f)
        @error($f) <div class="mb-3 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ $message }}</div> @enderror
    @endforeach

    @if ($myShift)
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ __('Your shift is open on :register since :time.', ['register' => $myShift->register->name, 'time' => $myShift->opened_at->format('H:i')]) }}
        </div>
    @endif

    <div class="card table-wrap">
        <table class="table">
            <thead><tr><th>{{ __('Branch') }}</th><th>{{ __('Register') }}</th><th class="text-end">{{ __('Ledger balance') }}</th><th>{{ __('Open shift') }}</th><th></th></tr></thead>
            <tbody class="divide-y divide-slate-100">
            @forelse ($registers as $r)
                <tr wire:key="reg-{{ $r->id }}">
                    <td>{{ $r->branch->code }}</td>
                    <td class="font-medium">{{ $r->name }} @if ($r->is_default)<x-status-badge color="sky">{{ __('Default') }}</x-status-badge>@endif</td>
                    <td class="text-end font-semibold">@can('accounting.view')<a href="{{ route('accounting.ledger', ['account' => $r->account_id, 'branch' => $r->branch_id]) }}" class="hover:text-brand-700">{{ money($r->balance) }}</a>@else{{ money($r->balance) }}@endcan</td>
                    <td>@if ($r->open){{ $r->open->user->name }} · <span dir="ltr">{{ $r->open->opened_at->format('Y-m-d H:i') }}</span>@else — @endif</td>
                    <td class="text-end">
                        @if ($r->open && (auth()->id() === $r->open->user_id || auth()->user()->can('cash.manage')))
                            <button wire:click="startClose({{ $r->open->id }})" class="btn-secondary btn-sm">{{ __('Count & close shift') }}</button>
                        @elseif (! $r->open && ! $myShift)
                            @can('cash.shift')<button wire:click="$set('openRegisterId', {{ $r->id }})" class="btn-secondary btn-sm">{{ __('Open shift') }}</button>@endcan
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="py-6 text-center text-slate-500">{{ __('No cash registers yet. One is created automatically per branch on first use.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
        @can('cash.manage')
            <form wire:submit="createRegister" class="flex flex-wrap items-end gap-2 border-t border-slate-200 p-3">
                <select wire:model="newRegister.branch_id" class="form-input w-auto"><option value="">{{ __('Branch') }}</option>@foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->displayName() }}</option>@endforeach</select>
                <input wire:model="newRegister.name" class="form-input w-auto" placeholder="{{ __('Register name') }}">
                <button class="btn-secondary btn-sm">{{ __('Add register') }}</button>
                @error('newRegister.branch_id') <p class="form-error">{{ $message }}</p> @enderror
                @error('newRegister.name') <p class="form-error">{{ $message }}</p> @enderror
            </form>
        @endcan
    </div>

    @if ($openRegisterId)
        <form wire:submit="openShift" class="card card-body mt-4 flex flex-wrap items-end gap-3">
            <div>
                <label class="form-label">{{ __('Counted opening cash') }}</label>
                <input wire:model="openFloat" inputmode="decimal" class="form-input" dir="ltr" placeholder="0.00">
                @error('openFloat') <p class="form-error">{{ $message }}</p> @enderror
                @error('opening_float') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <p class="max-w-md text-xs text-slate-500">{{ __('Count the cash in the drawer. Any difference from the ledger balance is posted to cash over/short.') }}</p>
            <button class="btn-primary">{{ __('Open shift') }}</button>
            <button type="button" wire:click="$set('openRegisterId', null)" class="btn-secondary">{{ __('Cancel') }}</button>
        </form>
    @endif

    @if ($closing)
        <form wire:submit="closeShift" class="card card-body mt-4 space-y-3">
            <h3 class="font-semibold">{{ __('Close shift — :register', ['register' => $closing->register->name]) }}</h3>
            <div class="grid gap-3 sm:grid-cols-3">
                <div class="rounded-lg bg-slate-50 p-3"><div class="text-xs text-slate-500">{{ __('Opening float') }}</div><div class="font-semibold">{{ money($closing->opening_float) }}</div></div>
                <div class="rounded-lg bg-slate-50 p-3"><div class="text-xs text-slate-500">{{ __('Expected cash') }}</div><div class="font-semibold">{{ money($expected) }}</div></div>
                <div>
                    <label class="form-label">{{ __('Counted cash') }} *</label>
                    <input wire:model="counted" inputmode="decimal" class="form-input" dir="ltr">
                    @error('counted') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            </div>
            <input wire:model="closeNotes" class="form-input" placeholder="{{ __('Notes') }}">
            <div class="flex gap-2"><button class="btn-primary">{{ __('Close shift') }}</button><button type="button" wire:click="$set('closingShiftId', null)" class="btn-secondary">{{ __('Cancel') }}</button></div>
        </form>
    @endif

    <div class="mt-6 grid gap-6 xl:grid-cols-2">
        <div class="card">
            <div class="border-b border-slate-200 px-4 py-3 font-semibold">{{ __('Daily cash reconciliation (closed shifts)') }}</div>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>{{ __('Register') }}</th><th>{{ __('Cashier') }}</th><th>{{ __('Closed') }}</th><th class="text-end">{{ __('Expected') }}</th><th class="text-end">{{ __('Counted') }}</th><th class="text-end">{{ __('Variance') }}</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                    @forelse ($recentShifts as $s)
                        <tr><td>{{ $s->register->name }}</td><td>{{ $s->user->name }}</td><td class="text-xs" dir="ltr">{{ $s->closed_at->format('Y-m-d H:i') }}</td>
                            <td class="text-end">{{ money($s->expected_cash) }}</td><td class="text-end">{{ money($s->counted_cash) }}</td>
                            <td class="text-end font-semibold {{ $s->variance === 0 ? 'text-emerald-700' : 'text-rose-700' }}">{{ money($s->variance) }}</td></tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-slate-500">{{ __('No closed shifts yet.') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card">
            <div class="border-b border-slate-200 px-4 py-3 font-semibold">{{ __('Cash and bank movements') }}</div>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>{{ __('Number') }}</th><th>{{ __('Type') }}</th><th>{{ __('From') }}</th><th>{{ __('To') }}</th><th class="text-end">{{ __('Amount') }}</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                    @forelse ($movements as $m)
                        <tr><td class="font-mono text-xs" dir="ltr">{{ $m->number }}</td><td>{{ __('treasury.'.$m->type) }}</td>
                            <td>{{ $m->from?->name ?? $m->counterAccount?->displayName() }}</td><td>{{ $m->to?->name ?? $m->counterAccount?->displayName() }}</td>
                            <td class="text-end font-semibold">{{ money($m->amount) }}</td></tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-slate-500">{{ __('No movements yet.') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <x-modal show="showMovement" :title="__('Deposit / withdrawal / transfer')">
        <form wire:submit="saveMovement" class="grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <label class="form-label">{{ __('Type') }}</label>
                <select wire:model.live="movement.type" class="form-input">
                    <option value="transfer">{{ __('treasury.transfer') }}</option>
                    <option value="deposit">{{ __('treasury.deposit') }}</option>
                    <option value="withdrawal">{{ __('treasury.withdrawal') }}</option>
                </select>
            </div>
            @if ($movement['type'] !== 'deposit')
                <div>
                    <label class="form-label">{{ __('From') }}</label>
                    <select wire:model="movement.from" class="form-input">
                        <option value="">—</option>
                        @foreach ($registers as $r)<option value="cash:{{ $r->id }}">{{ __('Cash') }} — {{ $r->branch->code }} — {{ $r->name }}</option>@endforeach
                        @foreach ($banks as $b)<option value="bank:{{ $b->id }}">{{ __('Bank') }} — {{ $b->name }}</option>@endforeach
                    </select>
                </div>
            @endif
            @if ($movement['type'] !== 'withdrawal')
                <div>
                    <label class="form-label">{{ __('To') }}</label>
                    <select wire:model="movement.to" class="form-input">
                        <option value="">—</option>
                        @foreach ($registers as $r)<option value="cash:{{ $r->id }}">{{ __('Cash') }} — {{ $r->branch->code }} — {{ $r->name }}</option>@endforeach
                        @foreach ($banks as $b)<option value="bank:{{ $b->id }}">{{ __('Bank') }} — {{ $b->name }}</option>@endforeach
                    </select>
                    @error('to') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            @endif
            @if ($movement['type'] !== 'transfer')
                <div class="sm:col-span-2">
                    <label class="form-label">{{ $movement['type'] === 'deposit' ? __('Source of funds (counter account)') : __('Purpose (counter account)') }}</label>
                    <select wire:model="movement.counter_account_id" class="form-input">
                        <option value="">—</option>
                        @foreach ($counterAccounts as $a)<option value="{{ $a->id }}">{{ $a->code }} — {{ $a->displayName() }}</option>@endforeach
                    </select>
                    @error('counter_account_id') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            @endif
            <div>
                <label class="form-label">{{ __('Amount') }} *</label>
                <input wire:model="movement.amount" inputmode="decimal" class="form-input" dir="ltr">
                @error('movement.amount') <p class="form-error">{{ $message }}</p> @enderror
                @error('amount') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="form-label">{{ __('Reference') }}</label>
                <input wire:model="movement.reference" class="form-input" dir="ltr">
            </div>
            <div class="sm:col-span-2"><input wire:model="movement.notes" class="form-input" placeholder="{{ __('Notes') }}"></div>
            <div class="flex justify-end gap-2 sm:col-span-2">
                <button type="button" class="btn-secondary" @click="open = false">{{ __('Cancel') }}</button>
                <button class="btn-primary" wire:loading.attr="disabled">{{ __('Save') }}</button>
            </div>
        </form>
    </x-modal>
</div>
