<div>
    <x-page-header :title="__('Leave requests')" :subtitle="__('Annual leave is checked against the yearly balance; you cannot decide your own request.')">
        <x-slot:actions><a href="{{ route('hr.employees') }}" class="btn-secondary">{{ __('Employees') }}</a></x-slot:actions>
    </x-page-header>

    @can('hr.manage')
        <form wire:submit="submit" class="card card-body mb-4 grid gap-3 sm:grid-cols-6">
            <div><select wire:model="form.employee_id" class="form-input"><option value="">{{ __('Employee') }}</option>@foreach ($employees as $e)<option value="{{ $e->id }}">{{ $e->name }}</option>@endforeach</select>@error('form.employee_id') <p class="form-error">{{ $message }}</p> @enderror</div>
            <select wire:model="form.type" class="form-input">@foreach (\App\Modules\HR\Models\LeaveRequest::TYPES as $t)<option value="{{ $t }}">{{ __('leave.'.$t) }}</option>@endforeach</select>
            <div><input type="date" wire:model="form.start" class="form-input">@error('form.start') <p class="form-error">{{ $message }}</p> @enderror</div>
            <div><input type="date" wire:model="form.end" class="form-input">@error('form.end') <p class="form-error">{{ $message }}</p> @enderror @error('form.days') <p class="form-error">{{ $message }}</p> @enderror</div>
            <input wire:model="form.reason" class="form-input" placeholder="{{ __('Reason') }}">
            <button class="btn-primary">{{ __('Add leave request') }}</button>
        </form>
    @endcan

    <div class="mb-3 flex gap-2">
        @foreach (['pending' => __('leave.status.pending'), 'approved' => __('leave.status.approved'), 'rejected' => __('leave.status.rejected'), 'cancelled' => __('leave.status.cancelled'), '' => __('All')] as $k => $label)
            <button wire:click="$set('status', '{{ $k }}')" @class(['btn-primary btn-sm' => $status === $k, 'btn-secondary btn-sm' => $status !== $k])>{{ $label }}</button>
        @endforeach
    </div>
    @include('livewire.hr.partials.leave-table', ['items' => $requests, 'showEmployee' => true, 'decide' => auth()->user()->can('hr.manage')])
    <div class="mt-3">{{ $requests->links() }}</div>
</div>
