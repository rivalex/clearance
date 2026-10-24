<form wire:submit="save" class="space-y-6 text-start">
    <div class="pb-4 border-b border-zinc-200 dark:border-zinc-700">
        <flux:heading size="lg">
            {{ $guardId ? __('clearance::ui.guards.form.edit_title') : __('clearance::ui.guards.form.new_title') }}
        </flux:heading>
        <flux:text class="text-zinc-500 dark:text-zinc-400">
            {{ __('clearance::ui.guards.form.desc') }}
        </flux:text>
    </div>

    @if($errorMessage)
        <div role="alert" class="rounded-md bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 px-4 py-3 text-sm text-red-700 dark:text-red-400">
            {{ $errorMessage }}
        </div>
    @endif

    <div class="space-y-4">
        <flux:input
            wire:model="name"
            label="{{ __('clearance::ui.guards.form.guard_name') }}"
            placeholder="{{ __('clearance::ui.guards.form.guard_name_placeholder') }}"
            required
            :disabled="$guardId !== null"
            :autofocus="$guardId === null"
        />

        <flux:input
            wire:model="driver"
            label="{{ __('clearance::ui.guards.form.driver') }}"
            placeholder="{{ __('clearance::ui.guards.form.driver_placeholder') }}"
            required
            :autofocus="$guardId !== null"
        />

        <flux:input
            wire:model="provider"
            label="{{ __('clearance::ui.guards.form.provider') }}"
            placeholder="{{ __('clearance::ui.guards.form.provider_placeholder') }}"
        />
    </div>

    <div class="flex items-center justify-between gap-2 pt-4 mt-2 border-t border-zinc-200 dark:border-zinc-700">
        <flux:modal.close>
            <flux:button variant="ghost">{{ __('clearance::ui.common.cancel') }}</flux:button>
        </flux:modal.close>

        <flux:button type="submit" variant="primary">
            {{ $guardId ? __('clearance::ui.guards.form.update') : __('clearance::ui.guards.form.create') }}
        </flux:button>
    </div>
</form>
