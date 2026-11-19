@if ($type === 'money')
    <td class="text-end {{ (int) $value < 0 ? 'text-rose-700' : '' }}">{{ $value === null ? '' : money((int) $value, false) }}</td>
@elseif ($type === 'percent')
    <td class="text-end {{ $value !== null && $value < 0 ? 'text-rose-700' : '' }}">{{ $value === null ? '—' : number_format($value / 100, 1).'%' }}</td>
@elseif ($type === 'int')
    <td class="text-end {{ (int) $value < 0 ? 'text-rose-700' : '' }}">{{ $value === null ? '' : number_format((int) $value) }}</td>
@else
    <td>{{ is_string($value) && str_starts_with($value, 'movement.') ? __($value) : $value }}</td>
@endif
