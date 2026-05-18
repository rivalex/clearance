<form wire:submit="save" class="space-y-6 text-start">
    <header>
        <h3 class="text-lg font-medium text-zinc-900 dark:text-zinc-100">
            {{ $guardId ? __('clearance::ui.guards.form.edit_title') : __('clearance::ui.guards.form.new_title') }}
        </h3>
        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
            {{ __('clearance::ui.guards.form.desc') }}
        </p>
    </header>

    @if($errorMessage)
        <p class="text-sm text-red-600 dark:text-red-400">{{ $errorMessage }}</p>
    @endif

    <div class="space-y-4">
        <flux:input
            wire:model="name"
            label="{{ __('clearance::ui.guards.form.guard_name') }}"
            placeholder="{{ __('clearance::ui.guards.form.guard_name_placeholder') }}"
            required
            :disabled="$guardId !== null"
        />

        <flux:input
            wire:model="driver"
            label="{{ __('clearance::ui.guards.form.driver') }}"
            placeholder="{{ __('clearance::ui.guards.form.driver_placeholder') }}"
            required
        />

        <flux:input
            wire:model="provider"
            label="{{ __('clearance::ui.guards.form.provider') }}"
            placeholder="{{ __('clearance::ui.guards.form.provider_placeholder') }}"
        />
    </div>

    <div class="flex gap-2 justify-end">
        <flux:modal.close>
            <flux:button variant="ghost">{{ __('clearance::ui.common.cancel') }}</flux:button>
        </flux:modal.close>

        <flux:button type="submit" variant="primary" color="green">
            {{ $guardId ? __('clearance::ui.guards.form.update') : __('clearance::ui.guards.form.create') }}
        </flux:button>
    </div>
</form>
