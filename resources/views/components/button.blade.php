@props(['variant' => 'primary', 'href' => null, 'type' => 'button'])

@php
    $variantClasses = [
        'primary'   => 'bg-blue-800 text-white hover:bg-blue-900',
        'success'   => 'bg-emerald-600 text-white hover:bg-emerald-700',
        'danger'    => 'bg-red-600 text-white hover:bg-red-700',
        'warning'   => 'bg-yellow-500 text-white hover:bg-yellow-600',
        'dark'      => 'bg-gray-700 text-white hover:bg-gray-800',
        'secondary' => 'border border-gray-300 bg-white text-gray-700 hover:bg-gray-50',
    ];
    $classes = 'inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-semibold shadow-sm transition-colors disabled:opacity-50 '.$variantClasses[$variant];
@endphp

@if($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->class($classes) }}>{{ $slot }}</button>
@endif
