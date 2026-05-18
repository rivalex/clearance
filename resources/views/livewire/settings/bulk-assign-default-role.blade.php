<div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-6">
    <h2 class="text-base font-semibold mb-1">{{ __('clearance::ui.settings.bulk.title') }}</h2>
    <p class="text-sm text-zinc-500 dark:text-zinc-400 mb-5">{{ __('clearance::ui.settings.bulk.description') }}</p>

    <div class="flex items-center gap-4">
        <flux:button wire:click="bulkAssignDefaultRole" variant="filled" size="sm"
                     wire:confirm="{{ __('clearance::ui.settings.bulk.confirm') }}">
            {{ __('clearance::ui.settings.bulk.action') }}
        </flux:button>

        @if($bulkMessage)
            <span class="text-sm {{ $bulkSuccess ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                {{ $bulkMessage }}
            </span>
        @endif
    </div>
</div>
