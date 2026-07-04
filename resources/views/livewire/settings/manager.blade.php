<div class="clearance space-y-3">
    <x-clearance::branding />
    <div class="mb-6">
        <h1 class="text-xl font-semibold">{{ __('clearance::ui.common.settings') }}</h1>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('clearance::ui.settings.general.description') }}</p>
    </div>

    <x-clearance::nav active="settings" />

    <livewire:clearance::settings.general-settings />
    <livewire:clearance::settings.bulk-assign-default-role />
    <livewire:clearance::settings.role-meta-table />
    <livewire:clearance::settings.guard-meta-table />
</div>

@assets
@once
    <link rel="stylesheet" href="{{ route('clearance.assets', 'css/clearance.min.css') }}">
@endonce
@endassets
