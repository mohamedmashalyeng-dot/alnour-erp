@props(['label', 'value', 'icon' => 'chart', 'tone' => 'brand', 'href' => null])
@php
    $tones = [
        'brand' => 'bg-brand-50 text-brand-700',
        'amber' => 'bg-amber-50 text-amber-700',
        'rose' => 'bg-rose-50 text-rose-700',
        'emerald' => 'bg-emerald-50 text-emerald-700',
        'sky' => 'bg-sky-50 text-sky-700',
    ];
    $tag = $href ? 'a' : 'div';
@endphp
<{{ $tag }} @if($href) href="{{ $href }}" @endif class="card card-body flex items-center gap-4 {{ $href ? 'hover:border-brand-200' : '' }}">
    <div class="flex size-11 shrink-0 items-center justify-center rounded-xl {{ $tones[$tone] ?? $tones['brand'] }}"><x-icon :name="$icon" class="size-6" /></div>
    <div class="min-w-0">
        <div class="truncate text-sm text-slate-500">{{ $label }}</div>
        <div class="truncate text-xl font-bold text-slate-900">{{ $value }}</div>
    </div>
</{{ $tag }}>
