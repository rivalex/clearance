<div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-6">
    <h2 class="text-base font-semibold mb-1">{{ __('clearance::ui.settings.appearance.title') }}</h2>
    <p class="text-sm text-gray-500 dark:text-gray-400 mb-5">{{ __('clearance::ui.settings.appearance.description') }}</p>

    <div>
        <flux:switch wire:model.live="forceDarkMode" label="{{ __('clearance::ui.settings.appearance.force_dark_mode') }}" />
        <p class="mt-1 text-xs text-gray-400">{{ __('clearance::ui.settings.appearance.force_dark_mode_hint') }}</p>
    </div>

    <div class="mt-5 flex items-center gap-4">
        <flux:button wire:click="saveAppearance" variant="primary" size="sm">
            {{ __('clearance::ui.settings.appearance.save') }}
        </flux:button>

        @if($saveMessage)
            <span class="text-sm text-green-600 dark:text-green-400">{{ $saveMessage }}</span>
        @endif
    </div>
</div>
