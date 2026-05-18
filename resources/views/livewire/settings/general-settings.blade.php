<div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-6">
    <h2 class="text-base font-semibold mb-1">{{ __('clearance::ui.settings.general.title') }}</h2>
    <p class="text-sm text-zinc-500 dark:text-zinc-400 mb-5">{{ __('clearance::ui.settings.general.description') }}</p>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
        <div>
            <flux:select wire:model="defaultRole" label="{{ __('clearance::ui.settings.general.default_role') }}">
                <option value="">{{ __('clearance::ui.settings.general.no_default_role') }}</option>
                @foreach($roles as $role)
                    <option value="{{ $role->name }}">{{ $role->name }} ({{ $role->guard_name }})</option>
                @endforeach
            </flux:select>
            <p class="mt-1 text-xs text-zinc-400">{{ __('clearance::ui.settings.general.default_role_hint') }}</p>
        </div>

        <div class="flex flex-col justify-center">
            <flux:checkbox wire:model="showIcons" label="{{ __('clearance::ui.settings.general.show_icons') }}" />
            <p class="mt-1 text-xs text-zinc-400 ml-6">{{ __('clearance::ui.settings.general.show_icons_hint') }}</p>
        </div>
    </div>

    <div class="mt-5 flex items-center gap-4">
        <flux:button wire:click="saveGeneral" variant="primary" size="sm">
            {{ __('clearance::ui.settings.general.save') }}
        </flux:button>

        @if($saveMessage)
            <span class="text-sm text-green-600 dark:text-green-400">{{ $saveMessage }}</span>
        @endif
    </div>
</div>
