<div>
    <x-page-header :title="__('My attendance & leave')" />
    @if (! $employee)
        <div class="card card-body text-center text-slate-500">{{ __('Your user account is not linked to an active employee record.') }}</div>
    @else
        @error('attendance') <div class="mb-3 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ $message }}</div> @enderror
        <div class="mb-4 grid gap-3 sm:grid-cols-3">
            <div class="card card-body">
                <div class="text-sm text-slate-500">{{ __('Today') }} <span dir="ltr">{{ today()->toDateString() }}</span></div>
                @if (! $today)
                    <button wire:click="checkIn" class="btn-primary mt-3 w-full">{{ __('Check in') }}</button>
                @elseif (! $today->check_out_at && $today->check_in_at)
                    <div class="mt-2 text-sm">{{ __('Checked in at') }} <span dir="ltr">{{ $today->check_in_at->format('H:i') }}</span>@if ($today->late_minutes) <span class="text-amber-700">({{ __('late :m min', ['m' => $today->late_minutes]) }})</span>@endif</div>
                    <button wire:click="checkOut" class="btn-secondary mt-3 w-full">{{ __('Check out') }}</button>
                @else
                    <div class="mt-2 text-sm">{{ __('attendance.'.$today->status) }} <span dir="ltr">{{ $today->check_in_at?->format('H:i') }} – {{ $today->check_out_at?->format('H:i') }}</span></div>
                @endif
            </div>
            <x-stat :label="__('Days present (month)')" :value="$summary['present'].' · '.__('Absences').' '.$summary['absent']" icon="users" tone="emerald" />
            <x-stat :label="__('Annual leave balance')" :value="$balance.' '.__('days')" icon="star" tone="sky" />
        </div>

        <form wire:submit="requestLeave" class="card card-body mb-4 grid gap-3 sm:grid-cols-5">
            <select wire:model="leave.type" class="form-input">@foreach (\App\Modules\HR\Models\LeaveRequest::TYPES as $t)<option value="{{ $t }}">{{ __('leave.'.$t) }}</option>@endforeach</select>
            <div><input type="date" wire:model="leave.start" class="form-input">@error('leave.start') <p class="form-error">{{ $message }}</p> @enderror</div>
            <div><input type="date" wire:model="leave.end" class="form-input">@error('leave.end') <p class="form-error">{{ $message }}</p> @enderror @error('leave.days') <p class="form-error">{{ $message }}</p> @enderror</div>
            <input wire:model="leave.reason" class="form-input" placeholder="{{ __('Reason') }}">
            <button class="btn-primary">{{ __('Request leave') }}</button>
        </form>
        @include('livewire.hr.partials.leave-table', ['items' => $leaves, 'showEmployee' => false, 'selfCancel' => true])

        <div class="card table-wrap mt-4">
            <table class="table">
                <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Status') }}</th><th>{{ __('Check in') }}</th><th>{{ __('Check out') }}</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                @forelse ($records as $r)
                    <tr><td dir="ltr">{{ $r->work_date->toDateString() }}</td><td>{{ __('attendance.'.$r->status) }}</td><td dir="ltr">{{ $r->check_in_at?->format('H:i') }}</td><td dir="ltr">{{ $r->check_out_at?->format('H:i') }}</td></tr>
                @empty
                    <tr><td colspan="4" class="py-6 text-center text-slate-500">{{ __('No attendance recorded for this month.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    @endif
</div>
