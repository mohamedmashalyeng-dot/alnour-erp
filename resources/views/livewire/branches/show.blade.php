<div>
    <x-page-header :title="$branch->displayName()" :subtitle="$branch->code">
        <x-slot:actions>
            @can('inventory.view')
                <a href="{{ route('inventory.index', ['branch' => $branch->id]) }}" class="btn-primary"><x-icon name="archive" class="size-4" />{{ __('View branch inventory') }}</a>
            @endcan
            <a href="{{ route('branches.index') }}" class="btn-secondary">{{ __('Back') }}</a>
        </x-slot:actions>
    </x-page-header>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat :label="__('Products in stock')" :value="number_format($summary['products'])" icon="cube" />
        <x-stat :label="__('Units in stock')" :value="number_format($summary['units'])" icon="archive" tone="emerald" />
        <x-stat :label="__('Reserved units')" :value="number_format($summary['reserved'])" icon="clock" tone="sky" />
        <x-stat :label="__('Low-stock alerts')" :value="number_format($summary['low'])" icon="alert" tone="rose" />
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <div class="card card-body">
            <h2 class="mb-3 font-semibold">{{ __('Branch information') }}</h2>
            <dl class="grid grid-cols-3 gap-y-2 text-sm">
                <dt class="text-slate-500">{{ __('Status') }}</dt>
                <dd class="col-span-2">@if ($branch->is_active)<x-status-badge color="emerald">{{ __('Active') }}</x-status-badge>@else<x-status-badge color="rose">{{ __('Inactive') }}</x-status-badge>@endif</dd>
                <dt class="text-slate-500">{{ __('Name (English)') }}</dt><dd class="col-span-2">{{ $branch->name_en ?: '—' }}</dd>
                <dt class="text-slate-500">{{ __('City') }}</dt><dd class="col-span-2">{{ $branch->city ?: '—' }}</dd>
                <dt class="text-slate-500">{{ __('Address') }}</dt><dd class="col-span-2">{{ $branch->address ?: '—' }}</dd>
                <dt class="text-slate-500">{{ __('Phone') }}</dt><dd class="col-span-2" dir="ltr">{{ $branch->phone ?: '—' }}</dd>
                <dt class="text-slate-500">{{ __('Email') }}</dt><dd class="col-span-2" dir="ltr">{{ $branch->email ?: '—' }}</dd>
                <dt class="text-slate-500">{{ __('Tax number') }}</dt><dd class="col-span-2" dir="ltr">{{ $branch->tax_number ?: '—' }}</dd>
                <dt class="text-slate-500">{{ __('Notes') }}</dt><dd class="col-span-2 whitespace-pre-line">{{ $branch->notes ?: '—' }}</dd>
            </dl>
        </div>
        <div class="card">
            <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                <h2 class="font-semibold">{{ __('Assigned users') }}</h2>
                @can('users.manage')<a href="{{ route('users.index') }}" class="text-sm text-brand-700 hover:underline">{{ __('Manage users') }}</a>@endcan
            </div>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>{{ __('Name') }}</th><th>{{ __('Role') }}</th><th>{{ __('Status') }}</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                    @forelse ($users as $u)
                        <tr>
                            <td>{{ $u->name }}</td>
                            <td>{{ $u->roles->map(fn ($r) => __('role.'.$r->name))->join('، ') }}</td>
                            <td>@if ($u->is_active)<x-status-badge color="emerald">{{ __('Active') }}</x-status-badge>@else<x-status-badge color="rose">{{ __('Inactive') }}</x-status-badge>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="text-center text-slate-500">{{ __('No users assigned.') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
