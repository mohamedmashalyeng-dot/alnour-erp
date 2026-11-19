<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>{{ $sale->invoice_number }}</title>
    <style>
        @page { size: 80mm auto; margin: 3mm; }
        * { box-sizing: border-box; }
        body { font-family: Tahoma, 'Segoe UI', Arial, sans-serif; width: 74mm; margin: 0 auto; font-size: 11px; color: #000; }
        .c { text-align: center; } .b { font-weight: bold; } .mono { font-family: 'Courier New', monospace; direction: ltr; unicode-bidi: embed; }
        table { width: 100%; border-collapse: collapse; } td { padding: 1px 0; vertical-align: top; }
        .end { text-align: end; } hr { border: 0; border-top: 1px dashed #000; margin: 4px 0; }
        .big { font-size: 15px; } .qr svg { width: 26mm; height: 26mm; }
        @media screen { body { margin-top: 10px; } .no-print { display: block; margin: 8px auto; } }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body onload="window.print()">
    <div class="c">
        <div class="b big">{{ $company }}</div>
        <div>{{ $sale->branch->displayName() }}</div>
        @if ($sale->branch->phone)<div class="mono">{{ $sale->branch->phone }}</div>@endif
        @if ($companyTax)<div>{{ __('Tax number') }}: <span class="mono">{{ $companyTax }}</span></div>@endif
    </div>
    <hr>
    <table>
        <tr><td>{{ __('Invoice') }}</td><td class="end mono">{{ $sale->invoice_number }}</td></tr>
        <tr><td>{{ __('Date') }}</td><td class="end mono">{{ $sale->sold_at->format('Y-m-d H:i') }}</td></tr>
        <tr><td>{{ __('Cashier') }}</td><td class="end">{{ $sale->cashier->name }}</td></tr>
        @if ($sale->customer)<tr><td>{{ __('Customer') }}</td><td class="end">{{ $sale->customer->name }}</td></tr>@endif
    </table>
    <hr>
    <table>
        @foreach ($sale->items as $item)
            <tr><td colspan="2">{{ $item->product_name }}</td></tr>
            <tr>
                <td class="mono">{{ $item->quantity }} × {{ money($item->unit_price, false) }}@if ($item->discount_amount) − {{ money($item->discount_amount, false) }}@endif</td>
                <td class="end mono">{{ money($item->line_total, false) }}</td>
            </tr>
        @endforeach
    </table>
    <hr>
    <table>
        <tr><td>{{ __('Subtotal') }}</td><td class="end mono">{{ money($sale->subtotal, false) }}</td></tr>
        @if ($sale->discount_total)<tr><td>{{ __('Discounts') }}</td><td class="end mono">-{{ money($sale->discount_total, false) }}</td></tr>@endif
        @if ($sale->tax_total)<tr><td>{{ $sale->prices_include_tax ? __('Tax (included)') : __('Tax') }}</td><td class="end mono">{{ money($sale->tax_total, false) }}</td></tr>@endif
        <tr class="b big"><td>{{ __('Total') }}</td><td class="end mono">{{ money($sale->total) }}</td></tr>
        @foreach ($payments as $p)
            <tr><td>{{ $p->direction === 'in' ? $p->method->label() : __('Refund').' '.$p->method->label() }}</td><td class="end mono">{{ money($p->applied, false) }}</td></tr>
            @if ($p->change_amount)<tr><td>{{ __('Change') }}</td><td class="end mono">{{ money($p->change_amount, false) }}</td></tr>@endif
        @endforeach
        @if ($sale->balanceDue() > 0 && $sale->status->value !== 'cancelled')<tr class="b"><td>{{ __('Balance due') }}</td><td class="end mono">{{ money($sale->balanceDue(), false) }}</td></tr>@endif
    </table>
    @if ($sale->status->value === 'cancelled')<div class="c b">*** {{ __('Cancelled') }} ***</div>@endif
    <hr>
    <div class="c qr">{!! $qr !!}</div>
    @if ($footer)<div class="c">{{ $footer }}</div>@endif
    <div class="c">{{ __('Thank you for your visit') }}</div>
    <button class="no-print" onclick="window.print()">{{ __('Print') }}</button>
</body>
</html>
