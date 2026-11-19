<div>
    <x-page-header :title="__('Expenses')">
        <x-slot:actions>
            @can('expenses.create')<a href="{{ route('expenses.create') }}" class="btn-primary"><x-icon name="plus" class="size-4" />{{ __('New expense') }}</a>@endcan
        </x-slot:actions>
    </x-page-header>
    @if ($pendingCount)
        <button wire:click="$set('status', 'submitted')" class="mb-4 block w-full rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-start text-sm text-amber-800">{{ __(':n expense(s) awaiting approval.', ['n' => $pendingCount]) }}</button>
    @endif
    @include('livewire.accounting.partials.filters')

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="card xl:col-span-2">
            <div class="no-print flex flex-wrap gap-3 border-b border-slate-200 p-3">
                <select wire:model.live="status" class="form-input max-w-xs">
                    <option value="">{{ __('All statuses') }}</option>
                    @foreach (['draft', 'submitted', 'approved', 'rejected'] as $s)<option value="{{ $s }}">{{ __('expense.'.$s) }}</option>@endforeach
                </select>
                <select wire:model.live="category" class="form-input max-w-xs">
                    <option value="">{{ __('All categories') }}</option>
                    @foreach ($categories as $c)<option value="{{ $c->id }}">{{ $c->displayName() }}</option>@endforeach
                </select>
            </div>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>{{ __('Number') }}</th><th>{{ __('Date') }}</th><th>{{ __('Branch') }}</th><th>{{ __('Category') }}</th><th>{{ __('Description') }}</th><th class="text-end">{{ __('Total') }}</th><th>{{ __('Status') }}</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                    @forelse ($expenses as $e)
                        <tr wire:key="exp-{{ $e->id }}">
                            <td><a href="{{ route('expenses.show', $e) }}" class="font-mono text-xs text-brand-700" dir="ltr">{{ $e->number }}</a></td>
                            <td class="text-xs" dir="ltr">{{ $e->expense_date->format('Y-m-d') }}</td>
                            <td>{{ $e->branch->code }}</td>
                            <td>{{ $e->category->displayName() }}</td>
                            <td class="max-w-xs truncate">{{ $e->description }}</td>
                            <td class="text-end font-semibold">{{ money($e->total) }}</td>
                            <td><x-status-badge :color="['draft' => 'slate', 'submitted' => 'amber', 'approved' => 'emerald', 'rejected' => 'rose'][$e->status]">{{ __('expense.'.$e->status) }}</x-status-badge></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-10 text-center text-slate-500">{{ __('No expenses found.') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <div class="p-3">{{ $expenses->links() }}</div>
        </div>
        <div class="card">
            <div class="border-b border-slate-200 px-4 py-3 font-semibold">{{ __('Approved expenses by category') }}</div>
            <table class="table"><tbody class="divide-y divide-slate-100">
                @forelse ($byCategory as $r)
                    <tr><td>{{ app()->getLocale() === 'en' && $r->name_en ? $r->name_en : $r->name }}</td><td>{{ $r->n }}</td><td class="text-end font-semibold">{{ money((int) $r->amount) }}</td></tr>
                @empty
                    <tr><td class="text-center text-slate-500">{{ __('No data yet.') }}</td></tr>
                @endforelse
            </tbody>
            <tfoot><tr class="bg-slate-50 font-bold"><td colspan="2">{{ __('Total') }}</td><td class="text-end">{{ money((int) $byCategory->sum('amount')) }}</td></tr></tfoot></table>
        </div>
    </div>
</div>
