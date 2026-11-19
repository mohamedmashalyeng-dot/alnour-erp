<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>{{ $title }} — {{ $party }}</title>
    @fonts
    @vite(['resources/css/app.css'])
    <style>@media print { @page { size: A4; margin: 12mm; } }</style>
</head>
<body class="bg-white p-6 text-sm">
    <form method="GET" class="no-print mb-4 flex flex-wrap items-end gap-3">
        <label>{{ __('From') }} <input type="date" name="from" value="{{ $from }}" class="form-input"></label>
        <label>{{ __('To') }} <input type="date" name="to" value="{{ $to }}" class="form-input"></label>
        <button class="btn-secondary btn-sm">{{ __('Update') }}</button>
        <button type="button" onclick="window.print()" class="btn-primary btn-sm">{{ __('Print') }}</button>
    </form>
    <div class="mb-4 flex justify-between">
        <div><div class="text-lg font-bold text-brand-700">{{ $company }}</div><div class="text-slate-500">{{ $title }}</div></div>
        <div class="text-end"><div class="font-semibold">{{ $party }}</div><div class="text-slate-500">{{ $partyInfo }}</div>
            <div class="text-xs text-slate-500" dir="ltr">{{ $from ?: '…' }} → {{ $to ?: now()->toDateString() }}</div></div>
    </div>
    @include('livewire.partials.statement-table', ['statement' => $statement, 'debitLabel' => $debitLabel, 'creditLabel' => $creditLabel])
    <p class="mt-4 text-xs text-slate-400" dir="ltr">{{ now()->format('Y-m-d H:i') }}</p>
</body>
</html>
