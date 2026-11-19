@php($rtl = app()->getLocale() === 'ar')
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: dejavusans; font-size: 9pt; color: #1e293b; }
        h1 { font-size: 14pt; color: #a84b08; margin: 0 0 4px; }
        .meta { color: #64748b; font-size: 8pt; margin-bottom: 8px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #14171c; color: #fff; padding: 4px; text-align: {{ $rtl ? 'right' : 'left' }}; }
        td { padding: 4px; border-bottom: 1px solid #e2e8f0; }
        tr:last-child td { font-weight: bold; background: #f1f5f9; }
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>
    <div class="meta">{{ $meta['company'] ?? '' }} — {{ $meta['branch'] ?? '' }} — {{ $meta['range'] ?? '' }}</div>
    <table>
        <thead><tr>@foreach ($matrix[0] as $h)<th>{{ $h }}</th>@endforeach</tr></thead>
        <tbody>
        @foreach (array_slice($matrix, 1) as $row)
            <tr>@foreach ($row as $c)<td>{{ $c }}</td>@endforeach</tr>
        @endforeach
        </tbody>
    </table>
</body>
</html>
