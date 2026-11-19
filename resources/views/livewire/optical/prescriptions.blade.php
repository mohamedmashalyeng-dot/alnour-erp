<div>
    <x-page-header :title="__('Prescriptions')" :subtitle="__('Confidential medical records. A prescription is never edited: void it and create a new one to correct it.')">
        <x-slot:actions>@can('prescriptions.manage')<button wire:click="create" class="btn-primary"><x-icon name="plus" class="size-4" />{{ __('New prescription') }}</button>@endcan</x-slot:actions>
    </x-page-header>

    <div class="card mb-4 p-3"><input wire:model.live.debounce.400ms="search" class="form-input max-w-md" placeholder="{{ __('Search number, customer name or phone') }}"></div>

    <div class="card table-wrap">
        <table class="table">
            <thead><tr><th>{{ __('Number') }}</th><th>{{ __('Customer') }}</th><th>{{ __('Type') }}</th><th>{{ __('Exam date') }}</th><th>{{ __('Expires') }}</th><th>{{ __('Branch') }}</th><th>{{ __('Status') }}</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
            @forelse ($prescriptions as $rx)
                <tr wire:key="rx-{{ $rx->id }}">
                    <td><a href="{{ route('optical.prescriptions.show', $rx) }}" class="font-mono text-xs text-brand-700 hover:underline" dir="ltr">{{ $rx->number }}</a></td>
                    <td>{{ $rx->customer->name }}<div class="text-xs text-slate-500" dir="ltr">{{ $rx->customer->phone }}</div></td>
                    <td>{{ __('rx.'.$rx->type) }}</td>
                    <td dir="ltr">{{ $rx->examined_on->toDateString() }}</td>
                    <td dir="ltr" class="{{ $rx->expires_on->isPast() ? 'text-rose-700' : '' }}">{{ $rx->expires_on->toDateString() }}</td>
                    <td>{{ $rx->branch->code }}</td>
                    <td>@if ($rx->status === 'void')<x-status-badge color="rose">{{ __('Void') }}</x-status-badge>@elseif ($rx->expires_on->isPast())<x-status-badge color="amber">{{ __('Expired') }}</x-status-badge>@else<x-status-badge color="emerald">{{ __('Valid') }}</x-status-badge>@endif</td>
                </tr>
            @empty
                <tr><td colspan="7" class="py-10 text-center text-slate-500">{{ __('No prescriptions yet.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
        <div class="p-3">{{ $prescriptions->links() }}</div>
    </div>

    <x-modal show="showForm" :title="__('New prescription')">
        <form wire:submit="save" class="space-y-4">
            <div class="grid gap-3 sm:grid-cols-3">
                <div class="sm:col-span-2">
                    <label class="form-label">{{ __('Customer') }} *</label>
                    @if ($selectedCustomer)
                        <div class="flex items-center gap-2"><span class="font-medium">{{ $selectedCustomer->name }}</span><span class="text-xs text-slate-500" dir="ltr">{{ $selectedCustomer->phone }}</span>
                            <button type="button" wire:click="$set('form.customer_id', '')" class="text-xs text-rose-700">{{ __('Change') }}</button></div>
                    @else
                        <input wire:model.live.debounce.300ms="customerSearch" class="form-input" placeholder="{{ __('Search customer by name or phone') }}">
                        @foreach ($customerResults as $c)<button type="button" wire:click="pickCustomer({{ $c->id }})" class="block w-full rounded px-2 py-1 text-start text-sm hover:bg-slate-100">{{ $c->name }} <span dir="ltr" class="text-slate-500">{{ $c->phone }}</span></button>@endforeach
                    @endif
                    @error('form.customer_id') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div><label class="form-label">{{ __('Branch') }}</label><select wire:model="form.branch_id" class="form-input">@foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->displayName() }}</option>@endforeach</select></div>
                <div><label class="form-label">{{ __('Type') }}</label><select wire:model.live="form.type" class="form-input"><option value="spectacles">{{ __('rx.spectacles') }}</option><option value="contact_lens">{{ __('rx.contact_lens') }}</option></select></div>
                <div><label class="form-label">{{ __('Exam date') }}</label><input type="date" wire:model="form.examined_on" class="form-input">@error('form.examined_on') <p class="form-error">{{ $message }}</p> @enderror</div>
                <div><label class="form-label">{{ __('Expires') }}</label><input type="date" wire:model="form.expires_on" class="form-input">@error('form.expires_on') <p class="form-error">{{ $message }}</p> @enderror</div>
            </div>

            <div class="table-wrap rounded-lg border border-slate-200" dir="ltr">
                <table class="table text-center">
                    <thead><tr><th></th><th>SPH</th><th>CYL</th><th>AXIS</th><th>ADD</th></tr></thead>
                    <tbody>
                    @foreach (['od' => 'OD (R)', 'os' => 'OS (L)'] as $eye => $label)
                        <tr><th>{{ $label }}</th>
                            @foreach (['sph', 'cyl', 'axis', 'add'] as $f)
                                <td><input wire:model="form.{{ $eye }}_{{ $f }}" inputmode="decimal" class="form-input w-20 py-1 text-center" placeholder="{{ $f === 'axis' ? '1-180' : '±0.00' }}">
                                    @error('form.'.$eye.'_'.$f) <p class="form-error text-xs">{{ $message }}</p> @enderror</td>
                            @endforeach
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="grid gap-3 sm:grid-cols-3" dir="ltr">
                <div><label class="form-label">PD (mm)</label><input wire:model="form.pd" inputmode="decimal" class="form-input" placeholder="63.0">@error('form.pd') <p class="form-error">{{ $message }}</p> @enderror</div>
                <div><label class="form-label">PD R / {{ __('monocular') }}</label><input wire:model="form.pd_right" inputmode="decimal" class="form-input">@error('form.pd_right') <p class="form-error">{{ $message }}</p> @enderror</div>
                <div><label class="form-label">PD L / {{ __('monocular') }}</label><input wire:model="form.pd_left" inputmode="decimal" class="form-input">@error('form.pd_left') <p class="form-error">{{ $message }}</p> @enderror</div>
                @if ($form['type'] === 'contact_lens')
                    <div><label class="form-label">BC (mm)</label><input wire:model="form.cl_base_curve" inputmode="decimal" class="form-input" placeholder="8.60">@error('form.cl_base_curve') <p class="form-error">{{ $message }}</p> @enderror</div>
                    <div><label class="form-label">DIA (mm)</label><input wire:model="form.cl_diameter" inputmode="decimal" class="form-input" placeholder="14.20">@error('form.cl_diameter') <p class="form-error">{{ $message }}</p> @enderror</div>
                @endif
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <div><label class="form-label">{{ __('Examiner') }}</label><input wire:model="form.examiner_name" class="form-input"></div>
                <div><label class="form-label">{{ __('Scan of the prescription (PDF/image, optional)') }}</label><input type="file" wire:model="file" accept=".pdf,image/*" class="form-input">@error('file') <p class="form-error">{{ $message }}</p> @enderror</div>
                <div class="sm:col-span-2"><label class="form-label">{{ __('Medical notes (encrypted)') }}</label><textarea wire:model="form.medical_notes" rows="2" class="form-input"></textarea></div>
            </div>
            <div class="flex justify-end gap-2"><button type="button" wire:click="$set('showForm', false)" class="btn-secondary">{{ __('Cancel') }}</button><button class="btn-primary">{{ __('Save') }}</button></div>
        </form>
    </x-modal>
</div>
