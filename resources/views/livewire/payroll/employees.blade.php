<div>
    <x-page-header :title="__('Employees & salaries')" :subtitle="__('Confidential: visible to payroll-authorized users only.')">
        <x-slot:actions>
            <a href="{{ route('payroll.index') }}" class="btn-secondary">{{ __('Payroll runs') }}</a>
            @can('payroll.manage')<button wire:click="create" class="btn-primary"><x-icon name="plus" class="size-4" />{{ __('New employee') }}</button>@endcan
        </x-slot:actions>
    </x-page-header>
    @error('amount') <div class="mb-3 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ $message }}</div> @enderror
    @error('cash_register_id') <div class="mb-3 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ $message }}</div> @enderror
    @error('bank_account_id') <div class="mb-3 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ $message }}</div> @enderror

    <div class="card table-wrap">
        <table class="table">
            <thead><tr><th>{{ __('Name') }}</th><th>{{ __('Job title') }}</th><th>{{ __('Branch') }}</th><th>{{ __('User account') }}</th><th class="text-end">{{ __('Base salary') }}</th><th class="text-end">{{ __('Outstanding advances') }}</th><th>{{ __('Status') }}</th><th></th></tr></thead>
            <tbody class="divide-y divide-slate-100">
            @forelse ($employees as $e)
                <tr wire:key="emp-{{ $e->id }}">
                    <td class="font-medium">{{ $e->name }}</td>
                    <td>{{ $e->job_title ?: '—' }}</td>
                    <td>{{ $e->branch->code }}</td>
                    <td>{{ $e->user?->name ?? '—' }}</td>
                    <td class="text-end">{{ money($e->base_salary) }}</td>
                    <td class="text-end {{ $e->outstanding > 0 ? 'text-amber-700' : '' }}">{{ money($e->outstanding) }}</td>
                    <td>@if ($e->is_active)<x-status-badge color="emerald">{{ __('Active') }}</x-status-badge>@else<x-status-badge color="rose">{{ __('Inactive') }}</x-status-badge>@endif</td>
                    <td class="text-end whitespace-nowrap">
                        <button wire:click="showHistory({{ $e->id }})" class="btn-secondary btn-sm">{{ __('Salary history') }}</button>
                        @can('payroll.manage')
                            <button wire:click="startAdvance({{ $e->id }})" class="btn-secondary btn-sm">{{ __('Advance') }}</button>
                            <button wire:click="edit({{ $e->id }})" class="btn-secondary btn-sm">{{ __('Edit') }}</button>
                        @endcan
                    </td>
                </tr>
                @if ($historyFor === $e->id)
                    <tr><td colspan="8" class="bg-slate-50">
                        <table class="table">
                            <thead><tr><th>{{ __('Payroll') }}</th><th>{{ __('Period') }}</th><th class="text-end">{{ __('Base salary') }}</th><th class="text-end">{{ __('Bonuses') }}</th><th class="text-end">{{ __('Deductions') }}</th><th class="text-end">{{ __('Advance recovery') }}</th><th class="text-end">{{ __('Net pay') }}</th><th>{{ __('Status') }}</th></tr></thead>
                            <tbody>
                            @forelse ($history as $h)
                                <tr><td class="font-mono text-xs" dir="ltr">{{ $h->run->number }}</td><td dir="ltr">{{ sprintf('%04d-%02d', $h->run->year, $h->run->month) }}</td>
                                    <td class="text-end">{{ money($h->base_salary) }}</td><td class="text-end">{{ money($h->bonuses) }}</td><td class="text-end">{{ money($h->deductions) }}</td>
                                    <td class="text-end">{{ money($h->advance_recovery) }}</td><td class="text-end font-semibold">{{ money($h->net_pay) }}</td><td>{{ __('payroll.'.$h->run->status) }}</td></tr>
                            @empty
                                <tr><td colspan="8" class="text-center text-slate-500">{{ __('No salary history yet.') }}</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </td></tr>
                @endif
            @empty
                <tr><td colspan="8" class="py-10 text-center text-slate-500">{{ __('No employees yet.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if ($advanceFor)
        <form wire:submit="giveAdvance" class="card card-body mt-4 grid gap-3 sm:grid-cols-5">
            <div class="sm:col-span-5 font-semibold">{{ __('Salary advance') }} — {{ $employees->firstWhere('id', $advanceFor)?->name }}</div>
            <div><input wire:model="advance.amount" inputmode="decimal" class="form-input" dir="ltr" placeholder="0.00">@error('advance.amount') <p class="form-error">{{ $message }}</p> @enderror</div>
            <select wire:model.live="advance.source" class="form-input"><option value="cash">{{ __('Cash') }}</option><option value="bank">{{ __('Bank') }}</option></select>
            @if ($advance['source'] === 'cash')
                <select wire:model="advance.cash_register_id" class="form-input"><option value="">{{ __('Cash register') }}</option>@foreach ($registers as $r)<option value="{{ $r->id }}">{{ $r->name }}</option>@endforeach</select>
            @else
                <select wire:model="advance.bank_account_id" class="form-input"><option value="">{{ __('Bank account') }}</option>@foreach ($banks as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach</select>
            @endif
            <input wire:model="advance.notes" class="form-input" placeholder="{{ __('Notes') }}">
            <div class="flex gap-2"><button class="btn-primary">{{ __('Save') }}</button><button type="button" wire:click="$set('advanceFor', null)" class="btn-secondary">{{ __('Cancel') }}</button></div>
        </form>
    @endif

    <x-modal show="showForm" :title="$editingId ? __('Edit employee') : __('New employee')">
        <form wire:submit="save" class="grid gap-4 sm:grid-cols-2">
            <div><label class="form-label">{{ __('Full name') }} *</label><input wire:model="form.name" class="form-input">@error('form.name') <p class="form-error">{{ $message }}</p> @enderror</div>
            <div><label class="form-label">{{ __('Job title') }}</label><input wire:model="form.job_title" class="form-input"></div>
            <div><label class="form-label">{{ __('Branch') }} *</label><select wire:model="form.branch_id" class="form-input">@foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->displayName() }}</option>@endforeach</select></div>
            <div><label class="form-label">{{ __('User account') }}</label><select wire:model="form.user_id" class="form-input"><option value="">—</option>@foreach ($users as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach</select>@error('form.user_id') <p class="form-error">{{ $message }}</p> @enderror</div>
            <div><label class="form-label">{{ __('Base salary') }} *</label><input wire:model="form.base_salary" inputmode="decimal" class="form-input" dir="ltr">@error('form.base_salary') <p class="form-error">{{ $message }}</p> @enderror</div>
            <div><label class="form-label">{{ __('Phone') }}</label><input wire:model="form.phone" class="form-input" dir="ltr"></div>
            <div><label class="form-label">{{ __('Hire date') }}</label><input type="date" wire:model="form.hired_on" class="form-input"></div>
            <label class="flex items-center gap-2 self-end text-sm"><input type="checkbox" wire:model="form.is_active" class="rounded border-slate-300 text-brand-600"> {{ __('Active') }}</label>
            <div class="flex justify-end gap-2 sm:col-span-2">
                <button type="button" class="btn-secondary" @click="open = false">{{ __('Cancel') }}</button>
                <button class="btn-primary">{{ __('Save') }}</button>
            </div>
        </form>
    </x-modal>
</div>
