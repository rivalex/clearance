<div>
    <x-clearance::branding />
    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <div class="min-w-0">
            <h1 class="text-xl font-semibold">{{ __('clearance::ui.common.settings') }}</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ __('clearance::ui.settings.general.description') }}</p>
        </div>
    </div>

    <x-clearance::nav active="settings" />

    <div class="space-y-6">
        <livewire:clearance::settings.general-settings />
        <livewire:clearance::settings.bulk-assign-default-role />
        <livewire:clearance::settings.role-meta-table />
        <livewire:clearance::settings.guard-meta-table />
    </div>
</div>

@assets
@once
@endonce
@endassets
