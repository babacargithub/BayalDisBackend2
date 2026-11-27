@props(['title', 'subtitle' => null])

<div class="bg-blue-800 rounded-xl shadow-sm overflow-hidden">
    <div class="px-6 py-5">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                {{ $above ?? '' }}
                <h2 class="text-xl font-bold text-white leading-tight">{{ $title }}</h2>
                @if($subtitle)
                    <p class="text-blue-200 text-sm mt-0.5">{{ $subtitle }}</p>
                @endif
            </div>
            <div class="flex flex-wrap items-center gap-2">
                {{ $slot }}
            </div>
        </div>
    </div>
</div>
