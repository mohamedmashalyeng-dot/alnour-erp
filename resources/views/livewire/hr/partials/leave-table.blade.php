{{-- Leave requests table. Expects $items; $showEmployee; optional $decide (manager actions) and $selfCancel. --}}
<div class="card table-wrap">
    <table class="table">
        <thead><tr>@if ($showEmployee)<th>{{ __('Employee') }}</th><th>{{ __('Branch') }}</th>@endif<th>{{ __('Type') }}</th><th>{{ __('Period') }}</th><th>{{ __('Days') }}</th><th>{{ __('Reason') }}</th><th>{{ __('Status') }}</th><th></th></tr></thead>
        <tbody class="divide-y divide-slate-100">
        @forelse ($items as $l)
            <tr wire:key="lv-{{ $l->id }}">
                @if ($showEmployee)<td class="font-medium">{{ $l->employee->name }}</td><td>{{ $l->branch->code }}</td>@endif
                <td>{{ __('leave.'.$l->type) }}</td>
                <td dir="ltr" class="whitespace-nowrap">{{ $l->start_date->toDateString() }} → {{ $l->end_date->toDateString() }}</td>
                <td>{{ $l->days }}</td>
                <td class="max-w-xs whitespace-normal text-xs">{{ $l->reason }}</td>
                <td><x-status-badge :color="['pending' => 'amber', 'approved' => 'emerald', 'rejected' => 'rose', 'cancelled' => 'slate'][$l->status]">{{ __('leave.status.'.$l->status) }}</x-status-badge>
                    @if ($l->decision_note)<div class="text-xs text-slate-500">{{ $l->decision_note }}</div>@endif
                    @if ($l->relationLoaded('decider') && $l->decider)<div class="text-xs text-slate-500">{{ $l->decider->name }}</div>@endif</td>
                <td class="whitespace-nowrap text-end">
                    @if (($decide ?? false) && $l->status === 'pending')
                        <input wire:model="notes.{{ $l->id }}" class="form-input mb-1 w-40 py-1 text-xs" placeholder="{{ __('Note (optional)') }}">
                        <button wire:click="decide({{ $l->id }}, true)" class="btn-primary btn-sm">{{ __('Approve') }}</button>
                        <button wire:click="decide({{ $l->id }}, false)" class="btn-danger btn-sm">{{ __('Reject') }}</button>
                    @endif
                    @if ((($decide ?? false) && $l->status === 'approved' && $l->start_date->isFuture()) || (($selfCancel ?? false) && $l->status === 'pending'))
                        <button wire:click="cancel({{ $l->id }})" wire:confirm="{{ __('Cancel this leave request?') }}" class="btn-secondary btn-sm">{{ __('Cancel') }}</button>
                    @endif
                    @error('leave.'.$l->id) <p class="form-error">{{ $message }}</p> @enderror
                </td>
            </tr>
        @empty
            <tr><td colspan="{{ $showEmployee ? 8 : 6 }}" class="py-8 text-center text-slate-500">{{ __('No leave requests.') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
