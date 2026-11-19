@props(['color' => 'slate'])
@php
    $map = [
        'slate' => 'bg-slate-100 text-slate-700',
        'emerald' => 'bg-emerald-100 text-emerald-800',
        'rose' => 'bg-rose-100 text-rose-800',
        'amber' => 'bg-amber-100 text-amber-800',
        'sky' => 'bg-sky-100 text-sky-800',
        'indigo' => 'bg-indigo-100 text-indigo-800',
    ];
@endphp
<span {{ $attributes->merge(['class' => 'badge '.($map[$color] ?? $map['slate'])]) }}>{{ $slot }}</span>
