@php use App\Modules\Optical\Support\Diopter; @endphp
<div>
    <x-page-header :title="__('Prescription').' '.$rx->number" :subtitle="$rx->customer->name">
        <x-slot:actions>
            <a href="{{ route('optical.prescriptions', ['customer' => $rx->customer_id]) }}" class="btn-secondary">{{ __('Customer prescriptions') }}</a>
            <button type="button" onclick="window.print()" class="btn-secondary"><x-icon name="printer" class="size-4" />{{ __('Print') }}</button>
        </x-slot:actions>
    </x-page-header>
    @if (session('status'))<div class="no-print mb-3 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>@endif
    @if ($rx->status === 'void')<div class="mb-3 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ __('Void') }}: {{ $rx->void_reason }}</div>@endif

    <div class="card card-body mb-4">
        <div class="mb-3 grid gap-2 text-sm sm:grid-cols-4">
            <div><span class="text-slate-500">{{ __('Customer') }}:</span> {{ $rx->customer->name }} <span dir="ltr">{{ $rx->customer->phone }}</span></div>
            <div><span class="text-slate-500">{{ __('Type') }}:</span> {{ __('rx.'.$rx->type) }}</div>
            <div><span class="text-slate-500">{{ __('Exam date') }}:</span> <span dir="ltr">{{ $rx->examined_on->toDateString() }}</span></div>
            <div><span class="text-slate-500">{{ __('Expires') }}:</span> <span dir="ltr" class="{{ $rx->expires_on->isPast() ? 'text-rose-700' : '' }}">{{ $rx->expires_on->toDateString() }}</span></div>
            <div><span class="text-slate-500">{{ __('Examiner') }}:</span> {{ $rx->examiner_name }}</div>
            <div><span class="text-slate-500">{{ __('Branch') }}:</span> {{ $rx->branch->displayName() }}</div>
            <div><span class="text-slate-500">{{ __('Recorded by') }}:</span> {{ $rx->creator?->name }}</div>
        </div>
        <div class="table-wrap" dir="ltr">
            <table class="table text-center">
                <thead><tr><th></th><th>SPH</th><th>CYL</th><th>AXIS</th><th>ADD</th></tr></thead>
                <tbody>
                @foreach (['od' => 'OD (R)', 'os' => 'OS (L)'] as $eye => $label)
                    <tr><th>{{ $label }}</th><td class="font-mono">{{ Diopter::format($rx->{$eye.'_sph'}) }}</td><td class="font-mono">{{ Diopter::format($rx->{$eye.'_cyl'}) }}</td>
                        <td class="font-mono">{{ $rx->{$eye.'_axis'} ? $rx->{$eye.'_axis'}.'°' : '' }}</td><td class="font-mono">{{ Diopter::format($rx->{$eye.'_add'}) }}</td></tr>
                @endforeach
                </tbody>
            </table>
            <div class="mt-2 flex flex-wrap gap-6 text-sm">
                @if ($rx->pd)<span>PD: <b>{{ Diopter::formatTenths($rx->pd) }}</b> mm</span>@endif
                @if ($rx->pd_right)<span>PD R/L: <b>{{ Diopter::formatTenths($rx->pd_right) }} / {{ Diopter::formatTenths($rx->pd_left) }}</b> mm</span>@endif
                @if ($rx->cl_base_curve)<span>BC: <b>{{ Diopter::format($rx->cl_base_curve, false) }}</b></span><span>DIA: <b>{{ Diopter::format($rx->cl_diameter, false) }}</b></span>@endif
            </div>
        </div>
        @if ($rx->medical_notes)<p class="mt-3 text-sm"><span class="text-slate-500">{{ __('Medical notes') }}:</span> {{ $rx->medical_notes }}</p>@endif
    </div>

    <div class="no-print grid gap-4 lg:grid-cols-2">
        <div class="card card-body">
            <div class="mb-2 font-semibold">{{ __('Lens compatibility check') }}</div>
            <select wire:model.live="lensId" class="form-input"><option value="">{{ __('Choose an ophthalmic lens') }}</option>@foreach ($lenses as $l)<option value="{{ $l->id }}">{{ $l->name }} ({{ $l->sku }})</option>@endforeach</select>
            @if ($lens)
                @if ($problems)
                    <ul class="mt-2 list-disc ps-5 text-sm text-rose-700">@foreach ($problems as $p)<li>{{ $p }}</li>@endforeach</ul>
                @else
                    <p class="mt-2 text-sm text-emerald-700">{{ __('This lens covers the prescription.') }}</p>
                @endif
            @endif
        </div>
        <div class="card card-body">
            <div class="mb-2 font-semibold">{{ __('Attachments') }}</div>
            @forelse ($rx->attachments as $a)
                <a href="{{ route('attachments.show', $a) }}" target="_blank" class="block text-sm text-brand-700 hover:underline">{{ $a->original_name }}</a>
            @empty
                <p class="text-sm text-slate-500">{{ __('No attachments.') }}</p>
            @endforelse
            @can('prescriptions.manage')
                <form wire:submit="upload" class="mt-3 flex gap-2"><input type="file" wire:model="file" accept=".pdf,image/*" class="form-input"><button class="btn-secondary">{{ __('Upload') }}</button></form>
                @error('file') <p class="form-error">{{ $message }}</p> @enderror
            @endcan
        </div>
        @can('prescriptions.manage')
            @if ($rx->status === 'active')
                <form wire:submit="void" class="card card-body lg:col-span-2 flex flex-wrap items-center gap-2">
                    <input wire:model="voidReason" class="form-input max-w-lg" placeholder="{{ __('Reason for voiding (e.g. data entry error, re-examined)') }}">
                    <button class="btn-danger" wire:confirm="{{ __('Void this prescription? This cannot be undone.') }}">{{ __('Void prescription') }}</button>
                    @error('void_reason') <p class="form-error">{{ $message }}</p> @enderror
                </form>
            @endif
        @endcan
    </div>
</div>
