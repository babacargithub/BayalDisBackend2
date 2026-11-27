@props(['title', 'closeAction', 'maxWidth' => 'max-w-3xl'])

<div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/50 p-4"
     role="dialog" aria-modal="true" aria-label="{{ $title }}"
     x-data x-on:keydown.escape.window="$wire.{{ $closeAction }}()">
    <div class="my-8 w-full {{ $maxWidth }} rounded-xl bg-white text-left shadow-xl">
        <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4">
            <div class="flex items-center gap-3">
                <span class="block h-5 w-1 rounded-full bg-red-600"></span>
                <h3 class="text-base font-bold text-gray-900">{{ $title }}</h3>
            </div>
            <x-icon-button icon="mdi-close" title="Fermer" wire:click="{{ $closeAction }}" />
        </div>
        <div class="space-y-4 px-6 py-5">
            {{ $slot }}
        </div>
    </div>
</div>
