<x-clearance::dark-scope>
    <x-clearance::branding />
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold">{{ __('clearance::ui.guards.title') }}</h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('clearance::ui.guards.description') }}</p>
        </div>
        <livewire:clearance::guards.new-guard />
    </div>

    <x-clearance::nav active="guards" />

    <div class="mb-4">
        <flux:input wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('clearance::ui.guards.search_placeholder') }}"
                    clearable />
    </div>

    <div class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
            <thead class="bg-gray-50 dark:bg-gray-900">
                <tr>
                    <th class="px-4 py-3 text-left font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide text-xs">{{ __('clearance::ui.common.guard') }}</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide text-xs">{{ __('clearance::ui.guards.driver') }}</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide text-xs">{{ __('clearance::ui.guards.provider') }}</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide text-xs">{{ __('clearance::ui.common.source') }}</th>
                    <th class="px-4 py-3 text-right font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide text-xs">{{ __('clearance::ui.common.actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse($guards as $name => $config)
                    <tr>
                        <td class="px-4 py-3 font-medium">
                            <div class="flex items-center gap-2">
                                @if($showIcons && !empty($guardMetas->get($name)?->icon_svg))
                                    <div class="inline-flex shrink-0 size-5 items-center" style="color: {{ \Rivalex\Clearance\Support\SvgSanitizer::safeCssColor($guardMetas->get($name)?->color) }}">{!! \Rivalex\Clearance\Support\SvgSanitizer::sanitize($guardMetas->get($name)->icon_svg) !!}</div>
                                @endif
                                <div class="flex items-baseline gap-2">
                                    <p>{{ \Illuminate\Support\Str::headline($guardMetas->get($name)?->display_name ?: $name) }}</p>
                                    <p class="text-gray-500 dark:text-gray-400 text-xs font-mono">{{ $guardMetas->get($name)?->display_name ?: $name }}</p>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ $config['driver'] ?? '-' }}</td>
                        <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ $config['provider'] ?? '-' }}</td>
                        <td class="px-4 py-3">
                            @if(isset($config['is_db']))
                                <flux:badge size="sm" color="zinc">{{ __('clearance::ui.guards.type_database') }}</flux:badge>
                            @else
                                <flux:badge size="sm" color="zinc">{{ __('clearance::ui.guards.type_config') }}</flux:badge>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @if(isset($config['is_db']))
                                <div class="flex items-center justify-end gap-1">
                                    <livewire:clearance::guards.edit-guard :guardId="$config['id']" :guardName="$name" :key="'edit-'.$name" />
                                    <livewire:clearance::guards.delete-guard :guardId="$config['id']" :name="$name" :key="'delete-'.$name" />
                                </div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-6 text-center text-gray-400">
                            {{ $search ? __('clearance::ui.guards.no_match') : __('clearance::ui.guards.empty') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-clearance::dark-scope>

@assets
@once
    <link rel="stylesheet" href="{{ route('clearance.assets', 'css/clearance.min.css') }}">
@endonce
@endassets
