@props(['on', 'delay' => 2000])

<div
    x-data="{ shown: false }"
    x-show="shown"
    x-transition:enter="transition ease-out duration-200"
    x-transition:enter-start="opacity-0"
    x-transition:enter-end="opacity-100"
    x-transition:leave="transition ease-in"
    x-transition:leave-start="opacity-100"
    x-transition:leave-end="opacity-0"
    @{{ $on }}.window="shown = true; setTimeout(() => shown = false, {{ $delay }})"
    style="display:none"
    {{ $attributes->merge(['class' => 'text-sm']) }}
>
    {{ $slot }}
</div>
