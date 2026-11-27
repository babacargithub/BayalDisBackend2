@props([
    'label',
    'value',
    'unit' => null,
    'hint' => null,
    'borderClass' => 'border-t-blue-800',
    'valueClass' => 'text-blue-800',
    'unitClass' => 'text-blue-400',
])

<div class="bg-white rounded-xl shadow-sm border border-gray-100 border-t-4 {{ $borderClass }} p-5">
    <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-3">{{ $label }}</p>
    <p class="text-2xl font-bold {{ $valueClass }} tabular-nums">
        {{ $value }}
        @if($unit)
            <span class="text-sm font-normal {{ $unitClass }}">{{ $unit }}</span>
        @endif
    </p>
    {{ $slot }}
    @if($hint)
        <p class="text-xs text-gray-400 mt-2">{{ $hint }}</p>
    @endif
</div>
