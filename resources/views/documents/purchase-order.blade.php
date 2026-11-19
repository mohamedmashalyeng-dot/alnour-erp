@php($rtl = app()->getLocale() === 'ar')
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: dejavusans; font-size: 10pt; color: #1e293b; }
        h1 { font-size: 16pt; color: #a84b08; margin: 0; }
        table { width: 100%; border-collapse: collapse; }
        .items th { background: #14171c; color: #fff; padding: 5px; text-align: {{ $rtl ? 'right' : 'left' }}; }
        .items td { padding: 5px; border-bottom: 1px solid #e2e8f0; }
        .num { text-align: {{ $rtl ? 'left' : 'right' }}; }
        .muted { color: #64748b; font-size: 9pt; }
        .box { border: 1px solid #e2e8f0; padding: 6px; }
    </style>
</head>
<body>
<table><tr>
    <td><h1>{{ $company }}</h1><div>{{ $po->branch->displayName() }}</div>@if ($companyTax)<div class="muted">{{ __('Tax number') }}: {{ $companyTax }}</div>@endif</td>
    <td class="num"><div style="font-size:15pt;font-weight:bold">{{ __('Purchase order') }}</div><div>{{ $po->number }}</div><div class="muted">{{ $po->status->label() }}</div></td>
</tr></table>
<br>
<table><tr>
    <td class="box" style="width:50%">
        <div class="muted">{{ __('Supplier') }}</div><b>{{ $po->supplier->name }}</b>
        @if ($po->supplier->company_name)<div>{{ $po->supplier->company_name }}</div>@endif
        @if ($po->supplier->phone)<div>{{ $po->supplier->phone }}</div>@endif
        @if ($po->supplier->tax_number)<div class="muted">{{ __('Tax number') }}: {{ $po->supplier->tax_number }}</div>@endif
    </td>
    <td class="box" style="width:50%">
        <div class="muted">{{ __('Order date') }}</div>{{ $po->order_date->format('Y-m-d') }}
        <div class="muted">{{ __('Supplier invoice') }}</div>{{ $po->supplier_invoice_number ?: '—' }}
        <div class="muted">{{ __('Receiving branch') }}</div>{{ $po->branch->displayName() }}
    </td>
</tr></table>
<br>
<table class="items">
    <thead><tr><th>#</th><th>{{ __('Product') }}</th><th>{{ __('SKU') }}</th><th class="num">{{ __('Quantity') }}</th><th class="num">{{ __('Unit cost') }}</th><th class="num">{{ __('Discount') }}</th><th class="num">{{ __('Tax') }}</th><th class="num">{{ __('Total') }}</th></tr></thead>
    <tbody>
    @foreach ($po->items as $i => $item)
        <tr><td>{{ $i + 1 }}</td><td>{{ $item->product->name }}</td><td>{{ $item->product->sku }}</td><td class="num">{{ $item->quantity }}</td><td class="num">{{ money($item->unit_cost, false) }}</td><td class="num">{{ money($item->discount_amount, false) }}</td><td class="num">{{ money($item->tax_amount, false) }}</td><td class="num">{{ money($item->line_total, false) }}</td></tr>
    @endforeach
    </tbody>
</table>
<br>
<table style="width:45%">
    <tr><td>{{ __('Subtotal') }}</td><td class="num">{{ money($po->subtotal) }}</td></tr>
    <tr><td>{{ __('Discounts') }}</td><td class="num">-{{ money($po->discount_total) }}</td></tr>
    <tr><td>{{ __('Tax') }}</td><td class="num">{{ money($po->tax_total) }}</td></tr>
    <tr><td><b>{{ __('Total') }}</b></td><td class="num"><b>{{ money($po->total) }}</b></td></tr>
    <tr><td>{{ __('Paid') }}</td><td class="num">{{ money($po->paid_amount) }}</td></tr>
    <tr><td>{{ __('Balance due') }}</td><td class="num">{{ money($po->balanceDue()) }}</td></tr>
</table>
@if ($po->notes)<p class="muted">{{ $po->notes }}</p>@endif
</body>
</html>
