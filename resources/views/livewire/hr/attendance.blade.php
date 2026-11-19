<div>
    <x-page-header :title="__('Attendance')" :subtitle="__('Daily attendance per branch. Lateness is calculated from the shift start set on each employee profile.')">
        <x-slot:actions>
            <a href="{{ route('hr.employees') }}" class="btn-secondary">{{ __('Employees') }}</a>
            @can('hr.manage')<button wire:click="markAllPresent" wire:confirm="{{ __('Mark every employee without a record (and not on leave) as present?') }}" class="btn-primary">{{ __('Mark remaining as present') }}</button>@endcan
        </x-slot:actions>
    </x-page-header>

    <div class="card mb-4 flex flex-wrap items-end gap-3 p-3">
        <div><label class="form-label">{{ __('Branch') }}</label><select wire:model.live="branch" class="form-input">@foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->displayName() }}</option>@endforeach</select></div>
        <div><label class="form-label">{{ __('Date') }}</label><input type="date" wire:model.live="date" max="{{ today()->toDateString() }}" class="form-input"></div>
    </div>

    <div class="card table-wrap">
        <table class="table">
            <thead><tr><th>{{ __('Employee') }}</th><th>{{ __('Status') }}</th><th>{{ __('Check in') }}</th><th>{{ __('Check out') }}</th><th>{{ __('Notes') }}</th><th></th></tr></thead>
            <tbody class="divide-y divide-slate-100">
            @forelse ($employees as $e)
                <tr wire:key="att-{{ $e->id }}-{{ $date }}">
                    <td class="font-medium">{{ $e->name }}<div class="text-xs text-slate-500">{{ $e->job_title }}@if ($e->shift_start) · {{ __('Shift') }} <span dir="ltr">{{ substr($e->shift_start, 0, 5) }}</span>@endif</div>
                        @if (isset($onLeave[$e->id]))<x-status-badge color="sky">{{ __('On leave') }} — {{ __('leave.'.$onLeave[$e->id]) }}</x-status-badge>@endif</td>
                    @can('hr.manage')
                        <td><select wire:model="rows.{{ $e->id }}.status" class="form-input py-1"><option value="">—</option>@foreach (\App\Modules\HR\Models\AttendanceRecord::STATUSES as $s)<option value="{{ $s }}">{{ __('attendance.'.$s) }}</option>@endforeach</select></td>
                        <td><input type="time" wire:model="rows.{{ $e->id }}.in" class="form-input w-28 py-1"></td>
                        <td><input type="time" wire:model="rows.{{ $e->id }}.out" class="form-input w-28 py-1"></td>
                        <td><input wire:model="rows.{{ $e->id }}.notes" class="form-input py-1"></td>
                        <td><button wire:click="save({{ $e->id }})" class="btn-secondary btn-sm">{{ __('Save') }}</button>@error('rows.'.$e->id) <p class="form-error">{{ $message }}</p> @enderror</td>
                    @else
                        <td>{{ ($rows[$e->id]['status'] ?? '') !== '' ? __('attendance.'.$rows[$e->id]['status']) : '—' }}</td>
                        <td dir="ltr">{{ $rows[$e->id]['in'] ?? '' }}</td><td dir="ltr">{{ $rows[$e->id]['out'] ?? '' }}</td><td class="text-xs">{{ $rows[$e->id]['notes'] ?? '' }}</td><td></td>
                    @endcan
                </tr>
            @empty
                <tr><td colspan="6" class="py-10 text-center text-slate-500">{{ __('No active employees in this branch.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
