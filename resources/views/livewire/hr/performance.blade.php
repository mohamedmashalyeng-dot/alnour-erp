<div>
    <x-page-header :title="__('Employee performance')" :subtitle="__('Sales are counted for employees linked to a user account (completed sales as cashier).')" />
    @include('livewire.accounting.partials.filters')
    <div class="card table-wrap">
        <table class="table">
            <thead><tr><th>{{ __('Employee') }}</th><th>{{ __('Branch') }}</th><th class="text-end">{{ __('Sales') }}</th>
                @if ($showMoney)<th class="text-end">{{ __('Sales value') }}</th><th class="text-end">{{ __('Average ticket') }}</th>@endif
                <th class="text-end">{{ __('Present') }}</th><th class="text-end">{{ __('Late') }}</th><th class="text-end">{{ __('Absent') }}</th><th class="text-end">{{ __('Leave days') }}</th><th class="text-end">{{ __('Hours worked') }}</th><th>{{ __('Last rating') }}</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
            @forelse ($rows as $r)
                <tr>
                    <td><a href="{{ route('hr.employees.show', $r['employee']) }}" class="font-medium text-brand-700 hover:underline">{{ $r['employee']->name }}</a><div class="text-xs text-slate-500">{{ $r['employee']->job_title }}</div></td>
                    <td>{{ $r['employee']->branch->code }}</td>
                    <td class="text-end">{{ $r['employee']->user_id ? $r['sales_count'] : '—' }}</td>
                    @if ($showMoney)<td class="text-end">{{ $r['employee']->user_id ? money($r['sales_total']) : '—' }}</td><td class="text-end">{{ $r['sales_count'] ? money($r['avg']) : '—' }}</td>@endif
                    <td class="text-end">{{ $r['att']['present'] }}</td>
                    <td class="text-end {{ $r['att']['late'] ? 'text-amber-700' : '' }}">{{ $r['att']['late'] }}</td>
                    <td class="text-end {{ $r['att']['absent'] ? 'text-rose-700' : '' }}">{{ $r['att']['absent'] }}</td>
                    <td class="text-end">{{ $r['att']['paid_leave'] + $r['att']['unpaid_leave'] }}</td>
                    <td class="text-end" dir="ltr">{{ intdiv($r['att']['worked_minutes'], 60) }}</td>
                    <td class="text-amber-600">{{ $r['rating'] ? str_repeat('★', $r['rating']) : '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="11" class="py-10 text-center text-slate-500">{{ __('No employees yet.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
