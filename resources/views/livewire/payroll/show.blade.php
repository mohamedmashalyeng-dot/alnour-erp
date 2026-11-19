<div>
    <x-page-header :title="__('Payroll').' '.$run->number" :subtitle="$run->branch->displayName().' — '.sprintf('%04d-%02d', $run->year, $run->month)">
        <x-slot:actions>
            @if ($run->status === 'draft' && auth()->user()->can('payroll.manage'))
                <button wire:click="approve" wire:confirm="{{ __('Approve this payroll? Salaries will be posted as an expense.') }}" class="btn-success">{{ __('Approve & post') }}</button>
            @endif
            <button onclick="window.print()" class="btn-secondary no-print"><x-icon name="printer" class="size-4" />{{ __('Print') }}</button>
            <a href="{{ route('payroll.index') }}" class="btn-secondary">{{ __('Back') }}</a>
        </x-slot:actions>
    </x-page-header>
    @foreach (['status', 'cash_register_id', 'bank_account_id', 'payment_source'] as $f)
        @error($f) <div class="mb-3 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ $message }}</div> @enderror
    @endforeach

    <div class="card table-wrap">
        <table class="table">
            <thead><tr><th>{{ __('Employee') }}</th><th class="text-end">{{ __('Base salary') }}</th><th>{{ __('Bonuses') }}</th><th>{{ __('Deductions') }}</th><th>{{ __('Advance recovery') }}</th><th class="text-end">{{ __('Net pay') }}</th><th></th></tr></thead>
            <tbody class="divide-y divide-slate-100">
            @foreach ($run->items as $i)
                <tr wire:key="pi-{{ $i->id }}">
                    <td class="font-medium">{{ $i->employee->name }}<div class="text-xs text-slate-500">{{ $i->employee->job_title }}</div>
                        @if ($i->attendance_deduction)<div class="text-xs text-amber-700">{{ __('Attendance deduction') }}: {{ money($i->attendance_deduction) }} ({{ __(':a absent, :u unpaid leave', ['a' => $i->absent_days, 'u' => $i->unpaid_leave_days]) }})</div>@endif</td>
                    <td class="text-end">{{ money($i->base_salary) }}</td>
                    @if ($run->status === 'draft' && auth()->user()->can('payroll.manage'))
                        <td><input wire:model="items.{{ $i->id }}.bonuses" inputmode="decimal" class="form-input w-24 py-1" dir="ltr"></td>
                        <td><input wire:model="items.{{ $i->id }}.deductions" inputmode="decimal" class="form-input w-24 py-1" dir="ltr"></td>
                        <td><input wire:model="items.{{ $i->id }}.advance_recovery" inputmode="decimal" class="form-input w-24 py-1" dir="ltr">
                            @if ($i->employee->outstandingAdvances())<div class="text-xs text-amber-700">{{ __('Outstanding') }}: {{ money($i->employee->outstandingAdvances()) }}</div>@endif</td>
                        <td class="text-end font-semibold">{{ money($i->net_pay) }}</td>
                        <td><button wire:click="saveItem({{ $i->id }})" class="btn-secondary btn-sm">{{ __('Save') }}</button>
                            @error('items.'.$i->id) <p class="form-error">{{ $message }}</p> @enderror
                            @foreach (['bonuses', 'deductions', 'advance_recovery'] as $f) @error('items.'.$i->id.'.'.$f) <p class="form-error">{{ $message }}</p> @enderror @endforeach</td>
                    @else
                        <td>{{ money($i->bonuses) }}</td><td>{{ money($i->deductions) }}</td><td>{{ money($i->advance_recovery) }}</td>
                        <td class="text-end font-semibold">{{ money($i->net_pay) }}</td><td></td>
                    @endif
                </tr>
            @endforeach
            </tbody>
            <tfoot><tr class="bg-slate-50 font-bold"><td>{{ __('Total') }}</td><td></td><td colspan="3"></td><td class="text-end">{{ money($run->total_net) }}</td><td></td></tr></tfoot>
        </table>
    </div>

    <div class="mt-4 flex items-center gap-3">
        <x-status-badge :color="['draft' => 'slate', 'approved' => 'amber', 'paid' => 'emerald'][$run->status]" class="text-sm">{{ __('payroll.'.$run->status) }}</x-status-badge>
        @if ($run->paid_at)<span class="text-sm text-slate-500" dir="ltr">{{ $run->paid_at->format('Y-m-d H:i') }}</span>@endif
    </div>

    @if ($run->status === 'approved')
        @can('payroll.manage')
            <form wire:submit="pay" class="card card-body mt-4 flex flex-wrap items-end gap-3">
                <div><label class="form-label">{{ __('Pay from') }}</label>
                    <select wire:model.live="source" class="form-input"><option value="bank">{{ __('Bank') }}</option><option value="cash">{{ __('Cash') }}</option></select></div>
                @if ($source === 'cash')
                    <select wire:model="registerId" class="form-input w-auto"><option value="">{{ __('Cash register') }}</option>@foreach ($registers as $r)<option value="{{ $r->id }}">{{ $r->name }}</option>@endforeach</select>
                @else
                    <select wire:model="bankId" class="form-input w-auto"><option value="">{{ __('Bank account') }}</option>@foreach ($banks as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach</select>
                @endif
                <button class="btn-success" wire:confirm="{{ __('Pay :amount in salaries?', ['amount' => money($run->total_net)]) }}">{{ __('Pay salaries') }}</button>
            </form>
        @endcan
    @endif
</div>
