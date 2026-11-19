<div>
    <x-page-header :title="__('Employees')" :subtitle="__('HR directory. Salaries and personal identifiers are shown only to authorized users.')">
        <x-slot:actions>
            <a href="{{ route('hr.attendance') }}" class="btn-secondary">{{ __('Attendance') }}</a>
            <a href="{{ route('hr.leave') }}" class="btn-secondary">{{ __('Leave requests') }}</a>
            @can('payroll.manage')<a href="{{ route('payroll.employees') }}" class="btn-primary"><x-icon name="plus" class="size-4" />{{ __('New employee') }}</a>@endcan
        </x-slot:actions>
    </x-page-header>

    <div class="card mb-4 flex flex-wrap items-end gap-3 p-3">
        <input wire:model.live.debounce.400ms="search" class="form-input max-w-xs" placeholder="{{ __('Search name, code, phone, department') }}">
        <select wire:model.live="branch" class="form-input w-auto"><option value="">{{ __('All branches') }}</option>@foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->displayName() }}</option>@endforeach</select>
        <select wire:model.live="status" class="form-input w-auto">
            <option value="">{{ __('All') }}</option>
            @foreach (\App\Modules\Payroll\Models\Employee::STATUSES as $s)<option value="{{ $s }}">{{ __('employment.'.$s) }}</option>@endforeach
        </select>
    </div>

    <div class="card table-wrap">
        <table class="table">
            <thead><tr><th>{{ __('Code') }}</th><th>{{ __('Name') }}</th><th>{{ __('Job title') }}</th><th>{{ __('Department') }}</th><th>{{ __('Branch') }}</th><th>{{ __('Phone') }}</th><th>{{ __('Hire date') }}</th><th>{{ __('Status') }}</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
            @forelse ($employees as $e)
                <tr wire:key="hr-{{ $e->id }}">
                    <td class="font-mono text-xs" dir="ltr">{{ $e->employee_code ?: '—' }}</td>
                    <td><a href="{{ route('hr.employees.show', $e) }}" class="font-medium text-brand-700 hover:underline">{{ $e->name }}</a>@if ($e->user)<div class="text-xs text-slate-500">{{ $e->user->name }}</div>@endif</td>
                    <td>{{ $e->job_title ?: '—' }}</td><td>{{ $e->department ?: '—' }}</td><td>{{ $e->branch->code }}</td>
                    <td dir="ltr">{{ $e->phone }}</td><td dir="ltr">{{ $e->hired_on?->toDateString() }}</td>
                    <td><x-status-badge :color="['active' => 'emerald', 'on_leave' => 'sky', 'suspended' => 'amber', 'terminated' => 'rose'][$e->employment_status] ?? 'slate'">{{ __('employment.'.$e->employment_status) }}</x-status-badge></td>
                </tr>
            @empty
                <tr><td colspan="8" class="py-10 text-center text-slate-500">{{ __('No employees yet.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
        <div class="p-3">{{ $employees->links() }}</div>
    </div>
</div>
