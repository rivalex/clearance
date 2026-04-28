@props(['textToCopy', 'showTooltip' => true])

<div
    x-data="{ copied: false }"
    {{ $attributes->class(['inline-flex flex-row items-center']) }}
>
    <button
        tabindex="-1"
        type="button"
        class="cursor-pointer"
        x-on:click.stop="await navigator.clipboard.writeText(@js($textToCopy)); copied = true; setTimeout(() => copied = false, 1000)"
        :title="copied ? 'Copied!' : 'Copy to clipboard'"
    >
        <div class="flex flex-row items-center gap-1.5 select-none">
            <span class="text-sky-600 dark:text-sky-400" x-cloak x-show="!copied">
                <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                </svg>
            </span>
            <span class="text-emerald-600 dark:text-emerald-400" x-cloak x-show="copied">
                <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                </svg>
            </span>
            <span>{{ $slot }}</span>
        </div>
    </button>
</div>
