<div class="clearance space-y-8">
    <x-clearance::branding />
    <div class="mb-2">
        <h1 class="text-xl font-semibold">{{ __('clearance::ui.common.settings') }}</h1>
        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ __('clearance::ui.settings.general.description') }}</p>
    </div>

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
