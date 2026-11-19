<div>
    <x-page-header :title="$employee->name" :subtitle="trim(($employee->job_title ?? '').' — '.$employee->branch->displayName(), ' —')">
        <x-slot:actions><a href="{{ route('hr.employees') }}" class="btn-secondary">{{ __('Employees') }}</a></x-slot:actions>
    </x-page-header>

    <div class="mb-4 grid gap-3 sm:grid-cols-4">
        <x-stat :label="__('Days present (month)')" :value="$summary['present']" icon="users" tone="emerald" />
        <x-stat :label="__('Late arrivals')" :value="$summary['late'].' ('.$summary['late_minutes'].' '.__('min').')'" icon="clock" tone="amber" />
        <x-stat :label="__('Absences')" :value="$summary['absent']" icon="alert" tone="rose" />
        <x-stat :label="__('Annual leave balance')" :value="$balance.' '.__('days')" icon="star" tone="sky" />
    </div>

    <div class="mb-4 flex flex-wrap gap-2">
        @foreach (['profile' => __('Profile'), 'attendance' => __('Attendance'), 'leave' => __('Leave'), 'salary' => __('Salary history'), 'reviews' => __('Performance reviews')] as $k => $label)
            @if ($k !== 'salary' || $canSalary)
                <button wire:click="$set('tab', '{{ $k }}')" @class(['btn-primary btn-sm' => $tab === $k, 'btn-secondary btn-sm' => $tab !== $k])>{{ $label }}</button>
            @endif
        @endforeach
    </div>

    @if ($tab === 'profile')
        <form wire:submit="saveProfile" class="card card-body grid gap-4 sm:grid-cols-3">
            @php $ro = ! auth()->user()->can('hr.manage'); @endphp
            <div><label class="form-label">{{ __('Employee code') }}</label><input wire:model="form.employee_code" class="form-input" dir="ltr" @disabled($ro)>@error('form.employee_code') <p class="form-error">{{ $message }}</p> @enderror</div>
            <div><label class="form-label">{{ __('Full name') }} *</label><input wire:model="form.name" class="form-input" @disabled($ro)>@error('form.name') <p class="form-error">{{ $message }}</p> @enderror</div>
            <div><label class="form-label">{{ __('Job title') }}</label><input wire:model="form.job_title" class="form-input" @disabled($ro)></div>
            <div><label class="form-label">{{ __('Department') }}</label><input wire:model="form.department" class="form-input" @disabled($ro)></div>
            <div><label class="form-label">{{ __('Phone') }}</label><input wire:model="form.phone" class="form-input" dir="ltr" @disabled($ro)></div>
            <div><label class="form-label">{{ __('Email') }}</label><input wire:model="form.email" type="email" class="form-input" dir="ltr" @disabled($ro)>@error('form.email') <p class="form-error">{{ $message }}</p> @enderror</div>
            <div><label class="form-label">{{ __('Employment type') }}</label><select wire:model="form.employment_type" class="form-input" @disabled($ro)>@foreach (\App\Modules\Payroll\Models\Employee::TYPES as $t)<option value="{{ $t }}">{{ __('employment.'.$t) }}</option>@endforeach</select></div>
            <div><label class="form-label">{{ __('Employment status') }}</label><select wire:model.live="form.employment_status" class="form-input" @disabled($ro)>@foreach (\App\Modules\Payroll\Models\Employee::STATUSES as $s)<option value="{{ $s }}">{{ __('employment.'.$s) }}</option>@endforeach</select></div>
            <div><label class="form-label">{{ __('Hire date') }}</label><input type="date" wire:model="form.hired_on" class="form-input" @disabled($ro)></div>
            @if (($form['employment_status'] ?? '') === 'terminated')
                <div><label class="form-label">{{ __('Termination date') }} *</label><input type="date" wire:model="form.terminated_on" class="form-input" @disabled($ro)>@error('form.terminated_on') <p class="form-error">{{ $message }}</p> @enderror</div>
            @endif
            <div><label class="form-label">{{ __('Annual leave days') }}</label><input type="number" min="0" max="60" wire:model="form.annual_leave_days" class="form-input" @disabled($ro)>@error('form.annual_leave_days') <p class="form-error">{{ $message }}</p> @enderror</div>
            <div><label class="form-label">{{ __('Shift start (for lateness)') }}</label><input type="time" wire:model="form.shift_start" class="form-input" @disabled($ro)>@error('form.shift_start') <p class="form-error">{{ $message }}</p> @enderror</div>
            <div><label class="form-label">{{ __('Emergency contact') }}</label><input wire:model="form.emergency_contact_name" class="form-input" @disabled($ro)></div>
            @can('hr.sensitive')
                <div class="sm:col-span-3 mt-2 border-t border-slate-200 pt-3 text-sm font-semibold text-rose-700">{{ __('Confidential information') }}</div>
                <div><label class="form-label">{{ __('National ID / Iqama') }}</label><input wire:model="form.national_id" class="form-input" dir="ltr" @disabled($ro)>@error('form.national_id') <p class="form-error">{{ $message }}</p> @enderror</div>
                <div><label class="form-label">{{ __('Emergency contact phone') }}</label><input wire:model="form.emergency_contact_phone" class="form-input" dir="ltr" @disabled($ro)></div>
                <div class="sm:col-span-3"><label class="form-label">{{ __('Address') }}</label><textarea wire:model="form.address" rows="2" class="form-input" @disabled($ro)></textarea></div>
            @else
                <div class="sm:col-span-3 text-xs text-slate-500">{{ __('National ID') }}: <span dir="ltr">{{ $employee->maskedNationalId() ?? '—' }}</span></div>
            @endcan
            <div class="sm:col-span-3"><label class="form-label">{{ __('Notes') }}</label><textarea wire:model="form.notes" rows="2" class="form-input" @disabled($ro)></textarea></div>
            @can('hr.manage')<div class="sm:col-span-3 flex justify-end"><button class="btn-primary">{{ __('Save') }}</button></div>@endcan
        </form>
    @elseif ($tab === 'attendance')
        <div class="card table-wrap">
            <div class="flex items-center gap-3 border-b border-slate-200 p-3"><input type="month" wire:model.live="month" class="form-input w-auto">
                <span class="text-sm text-slate-500">{{ __('Paid leave') }}: {{ $summary['paid_leave'] }} · {{ __('Unpaid leave') }}: {{ $summary['unpaid_leave'] }} · {{ __('Half days') }}: {{ $summary['half_days'] }}</span></div>
            <table class="table">
                <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Status') }}</th><th>{{ __('Check in') }}</th><th>{{ __('Check out') }}</th><th>{{ __('Late (min)') }}</th><th>{{ __('Worked') }}</th><th>{{ __('Source') }}</th><th>{{ __('Notes') }}</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                @forelse ($attendance as $a)
                    <tr><td dir="ltr">{{ $a->work_date->toDateString() }}</td><td>{{ __('attendance.'.$a->status) }}</td><td dir="ltr">{{ $a->check_in_at?->format('H:i') }}</td><td dir="ltr">{{ $a->check_out_at?->format('H:i') }}</td>
                        <td>{{ $a->late_minutes ?: '' }}</td><td dir="ltr">{{ $a->worked_minutes ? intdiv($a->worked_minutes, 60).':'.str_pad($a->worked_minutes % 60, 2, '0', STR_PAD_LEFT) : '' }}</td><td>{{ __('attendance.source.'.$a->source) }}</td><td class="text-xs">{{ $a->notes }}</td></tr>
                @empty
                    <tr><td colspan="8" class="py-8 text-center text-slate-500">{{ __('No attendance recorded for this month.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    @elseif ($tab === 'leave')
        @can('hr.manage')
            <form wire:submit="requestLeave" class="card card-body mb-4 grid gap-3 sm:grid-cols-5">
                <select wire:model="leave.type" class="form-input">@foreach (\App\Modules\HR\Models\LeaveRequest::TYPES as $t)<option value="{{ $t }}">{{ __('leave.'.$t) }}</option>@endforeach</select>
                <div><input type="date" wire:model="leave.start" class="form-input">@error('leave.start') <p class="form-error">{{ $message }}</p> @enderror</div>
                <div><input type="date" wire:model="leave.end" class="form-input">@error('leave.end') <p class="form-error">{{ $message }}</p> @enderror @error('leave.days') <p class="form-error">{{ $message }}</p> @enderror</div>
                <input wire:model="leave.reason" class="form-input" placeholder="{{ __('Reason') }}">
                <button class="btn-primary">{{ __('Add leave request') }}</button>
            </form>
        @endcan
        @include('livewire.hr.partials.leave-table', ['items' => $leaves, 'showEmployee' => false])
    @elseif ($tab === 'salary' && $canSalary)
        @can('payroll.manage')
            <form wire:submit="changeSalary" class="card card-body mb-4 grid gap-3 sm:grid-cols-4">
                <div><label class="form-label">{{ __('New base salary') }} ({{ __('current') }}: {{ money($employee->base_salary) }})</label><input wire:model="salary.amount" inputmode="decimal" class="form-input" dir="ltr">@error('salary.new_salary') <p class="form-error">{{ $message }}</p> @enderror</div>
                <div><label class="form-label">{{ __('Effective date') }}</label><input type="date" wire:model="salary.effective_on" class="form-input">@error('salary.effective_on') <p class="form-error">{{ $message }}</p> @enderror</div>
                <div><label class="form-label">{{ __('Reason') }}</label><input wire:model="salary.reason" class="form-input"></div>
                <div class="self-end"><button class="btn-primary">{{ __('Change salary') }}</button></div>
            </form>
        @endcan
        <div class="card table-wrap">
            <table class="table">
                <thead><tr><th>{{ __('Effective date') }}</th><th class="text-end">{{ __('Old salary') }}</th><th class="text-end">{{ __('New salary') }}</th><th>{{ __('Reason') }}</th><th>{{ __('Changed by') }}</th><th>{{ __('Recorded at') }}</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                @forelse ($salaryHistory as $s)
                    <tr><td dir="ltr">{{ $s->effective_on->toDateString() }}</td><td class="text-end">{{ money($s->old_salary) }}</td><td class="text-end font-semibold">{{ money($s->new_salary) }}</td>
                        <td>{{ $s->reason }}</td><td>{{ $s->changer?->name }}</td><td class="text-xs" dir="ltr">{{ $s->created_at }}</td></tr>
                @empty
                    <tr><td colspan="6" class="py-8 text-center text-slate-500">{{ __('No salary history yet.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    @elseif ($tab === 'reviews')
        @can('hr.manage')
            <form wire:submit="addReview" class="card card-body mb-4 grid gap-3 sm:grid-cols-4">
                <div><label class="form-label">{{ __('From') }}</label><input type="date" wire:model="review.period_start" class="form-input">@error('review.period_start') <p class="form-error">{{ $message }}</p> @enderror</div>
                <div><label class="form-label">{{ __('To') }}</label><input type="date" wire:model="review.period_end" class="form-input">@error('review.period_end') <p class="form-error">{{ $message }}</p> @enderror</div>
                <div><label class="form-label">{{ __('Rating') }}</label><select wire:model="review.rating" class="form-input">@for ($r = 5; $r >= 1; $r--)<option value="{{ $r }}">{{ $r }} — {{ __('rating.'.$r) }}</option>@endfor</select>@error('review.rating') <p class="form-error">{{ $message }}</p> @enderror</div>
                <div></div>
                <div class="sm:col-span-2"><label class="form-label">{{ __('Strengths') }}</label><textarea wire:model="review.strengths" rows="2" class="form-input"></textarea></div>
                <div class="sm:col-span-2"><label class="form-label">{{ __('Areas for improvement') }}</label><textarea wire:model="review.improvements" rows="2" class="form-input"></textarea></div>
                <div class="sm:col-span-4 flex justify-end"><button class="btn-primary">{{ __('Add review') }}</button></div>
            </form>
        @endcan
        <div class="space-y-3">
            @forelse ($reviews as $r)
                <div class="card card-body">
                    <div class="flex items-center justify-between"><span class="font-semibold" dir="ltr">{{ $r->period_start->toDateString() }} → {{ $r->period_end->toDateString() }}</span>
                        <span class="text-amber-600">{{ str_repeat('★', $r->rating) }}{{ str_repeat('☆', 5 - $r->rating) }}</span></div>
                    @if ($r->strengths)<p class="mt-2 text-sm"><span class="font-medium">{{ __('Strengths') }}:</span> {{ $r->strengths }}</p>@endif
                    @if ($r->improvements)<p class="mt-1 text-sm"><span class="font-medium">{{ __('Areas for improvement') }}:</span> {{ $r->improvements }}</p>@endif
                    <p class="mt-1 text-xs text-slate-500">{{ __('Reviewer') }}: {{ $r->reviewer?->name }}</p>
                </div>
            @empty
                <div class="card card-body text-center text-slate-500">{{ __('No performance reviews yet.') }}</div>
            @endforelse
        </div>
    @endif
</div>
