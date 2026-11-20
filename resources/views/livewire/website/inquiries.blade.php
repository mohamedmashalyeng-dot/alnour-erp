<div>
    <x-page-header :title="__('Website requests')" :subtitle="__('Orders, eye-exam bookings and questions sent from the website. Call or message the customer, then update the status.')">
        <x-slot:actions>
            @if (\Illuminate\Support\Facades\Route::has('shop.home'))
                <a href="{{ route('shop.home') }}" target="_blank" rel="noopener" class="btn-secondary"><x-icon name="eye" class="size-4" /> {{ __('View website') }}</a>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="mb-4 flex flex-wrap items-center gap-2">
        @php $open = ($counts['new'] ?? 0) + ($counts['contacted'] ?? 0); @endphp
        <button wire:click="$set('status', 'open')" @class(['btn-sm', 'btn-primary' => $status === 'open', 'btn-secondary' => $status !== 'open'])>{{ __('Open') }} <span class="opacity-70">{{ $open }}</span></button>
        @foreach ($statuses as $s)
            <button wire:click="$set('status', '{{ $s->value }}')" @class(['btn-sm', 'btn-primary' => $status === $s->value, 'btn-secondary' => $status !== $s->value])>{{ $s->label() }} <span class="opacity-70">{{ $counts[$s->value] ?? 0 }}</span></button>
        @endforeach
        <button wire:click="$set('status', '')" @class(['btn-sm', 'btn-primary' => $status === '', 'btn-secondary' => $status !== ''])>{{ __('All') }}</button>

        <div class="ms-auto flex flex-wrap gap-2">
            <select wire:model.live="type" class="form-input w-auto py-1.5" aria-label="{{ __('Type') }}">
                <option value="">{{ __('All types') }}</option>
                @foreach ($types as $t)<option value="{{ $t->value }}">{{ $t->label() }}</option>@endforeach
            </select>
            <input wire:model.live.debounce.400ms="search" type="search" class="form-input w-56 py-1.5" placeholder="{{ __('Search by name or phone') }}">
        </div>
    </div>

    <div class="grid gap-3 lg:grid-cols-2">
        @forelse ($inquiries as $i)
            <div class="card card-body" wire:key="inq-{{ $i->id }}">
                <div class="flex flex-wrap items-start gap-2">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <x-status-badge :color="$i->type->color()">{{ $i->type->label() }}</x-status-badge>
                            <x-status-badge :color="$i->status->color()">{{ $i->status->label() }}</x-status-badge>
                            <span class="text-xs text-slate-400" title="{{ $i->created_at }}">{{ $i->created_at->diffForHumans() }}</span>
                        </div>
                        <div class="mt-2 text-base font-semibold text-slate-900">{{ $i->name }}</div>
                        <div class="text-sm text-slate-500" dir="ltr">{{ $i->phone }}</div>
                    </div>
                    <div class="flex gap-1.5">
                        <a href="tel:{{ preg_replace('/[^0-9+]/', '', $i->phone) }}" class="btn-secondary btn-sm">{{ __('Call') }}</a>
                        @if ($wa = \App\Modules\Website\Livewire\InquiryIndex::whatsapp($i, $countryCode))
                            <a href="{{ $wa }}" target="_blank" rel="noopener" class="btn-sm btn bg-emerald-600 text-white hover:bg-emerald-700">{{ __('WhatsApp') }}</a>
                        @endif
                    </div>
                </div>

                <dl class="mt-3 grid gap-x-4 gap-y-1 text-sm sm:grid-cols-2">
                    @if ($i->product)
                        <div class="sm:col-span-2"><dt class="inline text-slate-500">{{ __('Product') }}:</dt>
                            <dd class="inline font-medium">
                                @can('products.view')<a href="{{ route('products.show', $i->product_id) }}" class="text-brand-700 hover:underline">{{ $i->product->displayName() }}</a>@else{{ $i->product->displayName() }}@endcan
                                <span class="font-mono text-xs text-slate-400" dir="ltr">{{ $i->product->sku }}</span>
                            </dd></div>
                    @endif
                    @if ($i->branch)<div><dt class="inline text-slate-500">{{ __('Branch') }}:</dt> <dd class="inline">{{ $i->branch->displayName() }}</dd></div>@endif
                    @if ($i->preferred_date)<div><dt class="inline text-slate-500">{{ __('Preferred date') }}:</dt> <dd class="inline" dir="ltr">{{ $i->preferred_date->toDateString() }}</dd></div>@endif
                </dl>
                @if (filled($i->message))
                    <p class="mt-3 rounded-xl bg-slate-50 p-3 text-sm whitespace-pre-line text-slate-700">{{ $i->message }}</p>
                @endif
                @if (filled($i->staff_note) && $noting !== $i->id)
                    <p class="mt-2 text-sm text-slate-600"><span class="font-semibold">{{ __('Note') }}:</span> {{ $i->staff_note }}
                        @if ($i->handler)<span class="text-xs text-slate-400">— {{ $i->handler->name }}</span>@endif</p>
                @endif

                @if ($noting === $i->id)
                    <form wire:submit="saveNote" class="mt-3 flex gap-2">
                        <input wire:model="note" class="form-input flex-1" placeholder="{{ __('e.g. Called, coming Saturday') }}" autofocus>
                        <button class="btn-primary btn-sm">{{ __('Save') }}</button>
                        <button type="button" wire:click="$set('noting', null)" class="btn-secondary btn-sm">{{ __('Cancel') }}</button>
                    </form>
                    @error('note') <p class="form-error">{{ $message }}</p> @enderror
                @else
                    <div class="mt-3 flex flex-wrap gap-1.5 border-t border-slate-100 pt-3">
                        @if ($i->status->value === 'new')
                            <button wire:click="move({{ $i->id }}, 'contacted')" class="btn-primary btn-sm">{{ __('Mark as contacted') }}</button>
                        @endif
                        @if (in_array($i->status->value, ['new', 'contacted'], true))
                            <button wire:click="move({{ $i->id }}, 'done')" class="btn-success btn-sm">{{ __('Completed') }}</button>
                        @endif
                        <button wire:click="startNote({{ $i->id }})" class="btn-secondary btn-sm">{{ __('Add note') }}</button>
                        @if ($i->status->value !== 'spam')
                            <button wire:click="move({{ $i->id }}, 'spam')" wire:confirm="{{ __('Mark this request as spam?') }}" class="btn-sm btn ms-auto text-slate-400 hover:text-rose-600">{{ __('Spam') }}</button>
                        @else
                            <button wire:click="move({{ $i->id }}, 'new')" class="btn-secondary btn-sm ms-auto">{{ __('Restore') }}</button>
                        @endif
                    </div>
                @endif
            </div>
        @empty
            <div class="card card-body text-center text-slate-500 lg:col-span-2">
                <x-icon name="clipboard" class="mx-auto mb-2 size-8 text-slate-300" />
                {{ __('No website requests yet.') }}
            </div>
        @endforelse
    </div>

    <div class="mt-4">{{ $inquiries->links() }}</div>
</div>
