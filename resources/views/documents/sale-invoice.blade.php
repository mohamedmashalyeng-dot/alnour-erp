@php($rtl = app()->getLocale() === 'ar')
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>{{ $sale->invoice_number }}</title>
    <style>
        body { font-family: {{ $pdf ? 'dejavusans' : "Tahoma, 'Segoe UI', Arial, sans-serif" }}; font-size: 11pt; color: #1e293b; }
        h1 { font-size: 18pt; margin: 0; color: #a84b08; }
        table { width: 100%; border-collapse: collapse; }
        .items th { background: #14171c; color: #fff; padding: 6px; font-size: 10pt; text-align: {{ $rtl ? 'right' : 'left' }}; }
        .items td { padding: 6px; border-bottom: 1px solid #e2e8f0; font-size: 10pt; }
        .num { text-align: {{ $rtl ? 'left' : 'right' }}; direction: ltr; }
        .muted { color: #64748b; font-size: 9pt; }
        .totals td { padding: 4px 6px; }
        .grand td { font-size: 13pt; font-weight: bold; border-top: 2px solid #fe801f; }
        .box { border: 1px solid #e2e8f0; padding: 8px; }
        @media print { .no-print { display: none; } @page { size: A4; margin: 12mm; } }
    </style>
</head>
<body>
@unless ($pdf)
    <div class="no-print" style="margin-bottom:12px"><button onclick="window.print()">{{ __('Print') }}</button></div>
@endunless
<table>
    <tr>
        <td style="width:60%">
            <h1>{{ $company }}</h1>
            <div>{{ $sale->branch->displayName() }} @if ($sale->branch->address) — {{ $sale->branch->address }} @endif</div>
            @if ($sale->branch->phone)<div class="muted" dir="ltr">{{ $sale->branch->phone }}</div>@endif
            @if ($companyTax)<div class="muted">{{ __('Tax number') }}: {{ $companyTax }}</div>@endif
        </td>
        <td style="width:40%" class="num">
            <div style="font-size:16pt;font-weight:bold">{{ $sale->tax_total > 0 ? __('Tax invoice') : __('Invoice') }}</div>
            <div>Invoice / فاتورة</div>
        </td>
    </tr>
</table>
<br>
<table>
    <tr>
        <td class="box" style="width:50%">
            <div class="muted">{{ __('Invoice number') }}</div><div dir="ltr"><b>{{ $sale->invoice_number }}</b></div>
            <div class="muted">{{ __('Date') }}</div><div dir="ltr">{{ $sale->sold_at->format('Y-m-d H:i') }}</div>
            <div class="muted">{{ __('Cashier') }}</div><div>{{ $sale->cashier->name }}</div>
        </td>
        <td class="box" style="width:50%">
            <div class="muted">{{ __('Customer') }}</div>
            <div><b>{{ $sale->customer?->name ?? __('Walk-in customer') }}</b></div>
            @if ($sale->customer?->phone)<div dir="ltr">{{ $sale->customer->phone }}</div>@endif
            @if ($sale->customer?->address)<div>{{ $sale->customer->address }}</div>@endif
        </td>
    </tr>
</table>
<br>
<table class="items">
    <thead><tr><th>#</th><th>{{ __('Product') }}</th><th>{{ __('SKU') }}</th><th class="num">{{ __('Quantity') }}</th><th class="num">{{ __('Unit price') }}</th><th class="num">{{ __('Discount') }}</th>@if ($sale->tax_total)<th class="num">{{ __('Tax') }}</th>@endif<th class="num">{{ __('Total') }}</th></tr></thead>
    <tbody>
    @foreach ($sale->items as $i => $item)
        <tr>
            <td>{{ $i + 1 }}</td>
            <td>{{ $item->product_name }}</td>
            <td dir="ltr">{{ $item->sku }}</td>
            <td class="num">{{ $item->quantity }}</td>
            <td class="num">{{ money($item->unit_price, false) }}</td>
            <td class="num">{{ money($item->discount_amount, false) }}</td>
            @if ($sale->tax_total)<td class="num">{{ money($item->tax_amount, false) }}</td>@endif
            <td class="num">{{ money($item->line_total, false) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
<br>
<table>
    <tr>
        <td style="width:50%;vertical-align:top">
            @if (! $pdf)<div style="width:110px">{!! $qr !!}</div>@endif
            @if ($payments->isNotEmpty())
                <div class="muted">{{ __('Payments') }}</div>
                @foreach ($payments as $p)
                    <div>{{ $p->direction === 'in' ? '' : __('Refund').' ' }}{{ $p->method->label() }}: <span dir="ltr">{{ money($p->applied) }}</span></div>
                @endforeach
            @endif
        </td>
        <td style="width:50%">
            <table class="totals">
                <tr><td>{{ __('Subtotal') }}</td><td class="num">{{ money($sale->subtotal) }}</td></tr>
                <tr><td>{{ __('Discounts') }}</td><td class="num">-{{ money($sale->discount_total) }}</td></tr>
                @if ($sale->tax_total)<tr><td>{{ $sale->prices_include_tax ? __('Tax (included)') : __('Tax') }}</td><td class="num">{{ money($sale->tax_total) }}</td></tr>@endif
                <tr class="grand"><td>{{ __('Total') }}</td><td class="num">{{ money($sale->total) }}</td></tr>
                @if ($sale->returned_amount)<tr><td>{{ __('Returned') }}</td><td class="num">-{{ money($sale->returned_amount) }}</td></tr>@endif
                <tr><td>{{ __('Paid') }}</td><td class="num">{{ money($sale->paid_amount - $sale->refunded_amount) }}</td></tr>
                @if ($sale->status->value !== 'cancelled')<tr><td><b>{{ __('Balance due') }}</b></td><td class="num"><b>{{ money(max(0, $sale->balanceDue())) }}</b></td></tr>@endif
            </table>
        </td>
    </tr>
</table>
@if ($sale->status->value === 'cancelled')<p style="color:#b91c1c;font-weight:bold;text-align:center">{{ __('Cancelled') }}</p>@endif
@if ($footer)<p class="muted" style="text-align:center">{{ $footer }}</p>@endif
</body>
</html>
