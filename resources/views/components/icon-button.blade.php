@props(['icon', 'title', 'color' => 'text-gray-600', 'href' => null])

@php $classes = 'inline-flex h-8 w-8 items-center justify-center rounded-full transition-colors hover:bg-gray-100 '.$color; @endphp

@if($href)
    <a href="{{ $href }}" title="{{ $title }}" aria-label="{{ $title }}" {{ $attributes->class($classes) }}>
        <i class="mdi {{ $icon }} text-lg"></i>
    </a>
@else
    <button type="button" title="{{ $title }}" aria-label="{{ $title }}" {{ $attributes->class($classes) }}>
        <i class="mdi {{ $icon }} text-lg"></i>
    </button>
@endif
