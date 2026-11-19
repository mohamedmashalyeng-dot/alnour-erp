@props(['show', 'title' => null, 'maxWidth' => 'max-w-2xl'])
{{-- Usage: <x-modal show="showForm" title="..."> with a public bool $showForm on the Livewire component. --}}
<div x-data="{ open: $wire.entangle('{{ $show }}') }" x-show="open" x-cloak
     class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 sm:items-center"
     @keydown.escape.window="open = false">
    <div class="card my-8 w-full {{ $maxWidth }}" @click.outside="open = false">
        @if ($title)
            <div class="flex items-center justify-between border-b border-slate-200 px-5 py-3">
                <h2 class="text-base font-semibold text-slate-900">{{ $title }}</h2>
                <button type="button" class="text-slate-400 hover:text-slate-600" @click="open = false" aria-label="{{ __('Close') }}"><x-icon name="x" /></button>
            </div>
        @endif
        <div class="card-body">
            {{ $slot }}
        </div>
    </div>
</div>
