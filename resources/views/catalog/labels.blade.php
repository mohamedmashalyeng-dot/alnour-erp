<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Print labels') }}</title>
    @fonts
    @vite(['resources/css/app.css'])
    <style>
        .label { width: 50mm; height: 30mm; padding: 1.5mm; box-sizing: border-box; overflow: hidden; page-break-inside: avoid; border: 1px dashed #cbd5e1; }
        .label svg { max-width: 100%; height: 34px; }
        .label .qr svg { width: 18mm; height: 18mm; }
        @media print { .label { border: none; } @page { margin: 4mm; } }
    </style>
</head>
<body class="bg-white">
    <form method="GET" class="no-print flex flex-wrap items-center gap-3 border-b border-slate-200 p-3">
        <input type="hidden" name="ids" value="{{ implode(',', $ids) }}">
        <label class="text-sm">{{ __('Copies per product') }}
            <input type="number" name="copies" min="1" max="50" value="{{ $copies }}" class="form-input inline w-20">
        </label>
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="qr" value="1" @checked($withQr)> {{ __('Include QR code') }}</label>
        <button class="btn-secondary btn-sm">{{ __('Update') }}</button>
        <button type="button" onclick="window.print()" class="btn-primary btn-sm">{{ __('Print') }}</button>
    </form>

    <div class="flex flex-wrap gap-1 p-2">
        @foreach ($labels as $label)
            <div class="label flex flex-col items-center justify-between text-center">
                <div class="w-full truncate text-[9px] font-semibold leading-tight">{{ $label['product']->displayName() }}</div>
                <div class="flex w-full items-center justify-center gap-1" dir="ltr">
                    <div class="flex flex-col items-center">
                        {!! $label['barcode'] !!}
                        <div class="font-mono text-[8px]">{{ $label['product']->barcode }}</div>
                    </div>
                    @if ($label['qr'])<div class="qr">{!! $label['qr'] !!}</div>@endif
                </div>
                <div class="flex w-full justify-between text-[9px]" dir="ltr">
                    <span class="font-mono">{{ $label['product']->sku }}</span>
                    <span class="font-bold">{{ money($label['product']->selling_price) }}</span>
                </div>
            </div>
        @endforeach
    </div>
</body>
</html>
