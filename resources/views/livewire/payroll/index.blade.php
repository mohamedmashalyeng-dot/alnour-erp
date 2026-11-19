<div>
    <x-page-header :title="__('Payroll')">
        <x-slot:actions><a href="{{ route('payroll.employees') }}" class="btn-secondary">{{ __('Employees & salaries') }}</a></x-slot:actions>
    </x-page-header>
    @can('payroll.manage')
        <form wire:submit="create" class="card card-body mb-4 flex flex-wrap items-end gap-3">
            <div><label class="form-label">{{ __('Branch') }}</label><select wire:model="branchId" class="form-input">@foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->displayName() }}</option>@endforeach</select></div>
            <div><label class="form-label">{{ __('Month') }}</label><input type="month" wire:model="period" class="form-input">@error('period') <p class="form-error">{{ $message }}</p> @enderror @error('month') <p class="form-error">{{ $message }}</p> @enderror</div>
            <button class="btn-primary">{{ __('Prepare payroll') }}</button>
        </form>
    @endcan
    <div class="card table-wrap">
        <table class="table">
            <thead><tr><th>{{ __('Number') }}</th><th>{{ __('Period') }}</th><th>{{ __('Branch') }}</th><th>{{ __('Employees') }}</th><th class="text-end">{{ __('Gross') }}</th><th class="text-end">{{ __('Net pay') }}</th><th>{{ __('Status') }}</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
            @forelse ($runs as $r)
                <tr>
                    <td><a href="{{ route('payroll.show', $r) }}" class="font-mono text-xs text-brand-700" dir="ltr">{{ $r->number }}</a></td>
                    <td dir="ltr" class="text-start">{{ sprintf('%04d-%02d', $r->year, $r->month) }}</td>
                    <td>{{ $r->branch->code }}</td><td>{{ $r->items_count }}</td>
                    <td class="text-end">{{ money($r->total_gross) }}</td><td class="text-end font-semibold">{{ money($r->total_net) }}</td>
                    <td><x-status-badge :color="['draft' => 'slate', 'approved' => 'amber', 'paid' => 'emerald'][$r->status]">{{ __('payroll.'.$r->status) }}</x-status-badge></td>
                </tr>
            @empty
                <tr><td colspan="7" class="py-10 text-center text-slate-500">{{ __('No payroll runs yet.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
