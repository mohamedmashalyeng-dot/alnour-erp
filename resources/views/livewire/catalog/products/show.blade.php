<div>
    <x-page-header :title="$product->displayName()" :subtitle="$product->product_type->label()">
        <x-slot:actions>
            @can('products.print-labels')
                <a href="{{ route('products.labels', ['ids' => $product->id]) }}" target="_blank" class="btn-secondary"><x-icon name="printer" class="size-4" />{{ __('Print label') }}</a>
            @endcan
            @can('products.update')
                <a href="{{ route('products.edit', $product) }}" class="btn-primary"><x-icon name="pencil" class="size-4" />{{ __('Edit') }}</a>
            @endcan
            <a href="{{ route('products.index') }}" class="btn-secondary">{{ __('Back') }}</a>
        </x-slot:actions>
    </x-page-header>

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            <div class="card card-body">
                <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                    <div class="flex justify-between gap-2 border-b border-slate-100 pb-2"><dt class="text-slate-500">{{ __('SKU') }}</dt><dd class="font-mono" dir="ltr">{{ $product->sku }}</dd></div>
                    <div class="flex justify-between gap-2 border-b border-slate-100 pb-2"><dt class="text-slate-500">{{ __('Barcode') }}</dt><dd class="font-mono" dir="ltr">{{ $product->barcode }}</dd></div>
                    <div class="flex justify-between gap-2 border-b border-slate-100 pb-2"><dt class="text-slate-500">{{ __('Category') }}</dt><dd>{{ $product->category?->displayName() ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-2 border-b border-slate-100 pb-2"><dt class="text-slate-500">{{ __('Brand') }}</dt><dd>{{ $product->brand?->displayName() ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-2 border-b border-slate-100 pb-2"><dt class="text-slate-500">{{ __('Model') }}</dt><dd dir="ltr">{{ $product->model ?: '—' }}</dd></div>
                    <div class="flex justify-between gap-2 border-b border-slate-100 pb-2"><dt class="text-slate-500">{{ __('Color') }}</dt><dd>{{ $product->color ?: '—' }}</dd></div>
                    <div class="flex justify-between gap-2 border-b border-slate-100 pb-2"><dt class="text-slate-500">{{ __('Size') }}</dt><dd dir="ltr">{{ $product->size ?: '—' }}</dd></div>
                    <div class="flex justify-between gap-2 border-b border-slate-100 pb-2"><dt class="text-slate-500">{{ __('Frame type') }}</dt><dd>{{ $product->frame_type?->label() ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-2 border-b border-slate-100 pb-2"><dt class="text-slate-500">{{ __('Frame material') }}</dt><dd>{{ $product->frame_material?->label() ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-2 border-b border-slate-100 pb-2"><dt class="text-slate-500">{{ __('Lens type') }}</dt><dd>{{ $product->lens_type?->label() ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-2 border-b border-slate-100 pb-2"><dt class="text-slate-500">{{ __('Gender') }}</dt><dd>{{ $product->gender?->label() ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-2 border-b border-slate-100 pb-2"><dt class="text-slate-500">{{ __('Default supplier') }}</dt><dd>{{ $product->supplier?->name ?? '—' }}</dd></div>
                    @foreach ($product->specifications ?? [] as $key => $value)
                        <div class="flex justify-between gap-2 border-b border-slate-100 pb-2"><dt class="text-slate-500">{{ __('spec.'.$key) }}</dt><dd dir="ltr">{{ $value }}</dd></div>
                    @endforeach
                    <div class="flex justify-between gap-2 border-b border-slate-100 pb-2"><dt class="text-slate-500">{{ __('Selling price') }}</dt><dd class="font-semibold">{{ money($product->selling_price) }}</dd></div>
                    @can('products.view-cost')
                        <div class="flex justify-between gap-2 border-b border-slate-100 pb-2"><dt class="text-slate-500">{{ __('Purchase price') }}</dt><dd>{{ money($product->purchase_price) }}</dd></div>
                        <div class="flex justify-between gap-2 border-b border-slate-100 pb-2"><dt class="text-slate-500">{{ __('Average cost') }}</dt><dd>{{ money($product->cost_price) }}</dd></div>
                    @endcan
                    <div class="flex justify-between gap-2 border-b border-slate-100 pb-2"><dt class="text-slate-500">{{ __('Status') }}</dt><dd>@if ($product->is_active)<x-status-badge color="emerald">{{ __('Active') }}</x-status-badge>@else<x-status-badge color="rose">{{ __('Inactive') }}</x-status-badge>@endif</dd></div>
                </dl>
                @if ($product->description)
                    <p class="mt-4 whitespace-pre-line text-sm text-slate-700">{{ $product->description }}</p>
                @endif
            </div>

            <div class="card">
                <div class="border-b border-slate-200 px-4 py-3 font-semibold">{{ __('Stock by branch') }}</div>
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>{{ __('Branch') }}</th><th>{{ __('On hand') }}</th><th>{{ __('Reserved') }}</th><th>{{ __('Available') }}</th><th>{{ __('Minimum') }}</th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                        @forelse ($stock as $row)
                            <tr>
                                <td>{{ $row->branch->displayName() }}</td>
                                <td class="font-semibold {{ $row->isLow() ? 'text-rose-600' : '' }}">{{ $row->quantity }}</td>
                                <td>{{ $row->reserved_quantity }}</td>
                                <td>{{ $row->available() }}</td>
                                <td>{{ $row->min_stock_level }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-slate-500">{{ __('No stock recorded yet.') }}</td></tr>
                        @endforelse
                        </tbody>
                        @if ($stock->count() > 1)
                            <tfoot><tr class="bg-slate-50 font-semibold"><td>{{ __('Total') }}</td><td>{{ $stock->sum('quantity') }}</td><td>{{ $stock->sum('reserved_quantity') }}</td><td>{{ $stock->sum(fn ($r) => $r->available()) }}</td><td></td></tr></tfoot>
                        @endif
                    </table>
                </div>
            </div>

            @if ($movements->isNotEmpty())
                <div class="card">
                    <div class="border-b border-slate-200 px-4 py-3 font-semibold">{{ __('Recent stock movements') }}</div>
                    @include('livewire.inventory.partials.movements-table', ['movements' => $movements])
                </div>
            @endif
        </div>

        <div class="space-y-6">
            @if ($product->images->isNotEmpty())
                <div class="card card-body">
                    <div class="grid grid-cols-2 gap-2">
                        @foreach ($product->images as $img)
                            <img src="{{ $img->url() }}" alt="" class="aspect-square w-full rounded-lg border object-cover">
                        @endforeach
                    </div>
                </div>
            @endif
            <div class="card card-body text-center">
                <h3 class="mb-3 font-semibold">{{ __('Barcode') }}</h3>
                <div class="flex justify-center overflow-hidden" dir="ltr">{!! $barcodeSvg !!}</div>
                <div class="mt-1 font-mono text-sm" dir="ltr">{{ $product->barcode }}</div>
            </div>
            <div class="card card-body text-center">
                <h3 class="mb-3 font-semibold">{{ __('QR code') }}</h3>
                <div class="mx-auto w-40" dir="ltr">{!! $qrSvg !!}</div>
                <div class="mt-1 font-mono text-xs break-all text-slate-500" dir="ltr">{{ $product->qr_code ?: $product->sku }}</div>
            </div>
        </div>
    </div>
</div>
