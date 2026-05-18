<div class="clearance">
    <x-clearance::branding />
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold">{{ __('clearance::ui.guards.title') }}</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ __('clearance::ui.guards.description') }}</p>
        </div>
        <livewire:clearance::guards.new-guard />
    </div>

    <div class="mb-4">
        <flux:input wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('clearance::ui.guards.search_placeholder') }}"
                    clearable />
    </div>

    <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800">
        <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700 text-sm">
            <thead class="bg-zinc-50 dark:bg-zinc-900">
                <tr>
                    <th class="px-4 py-3 text-left font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide text-xs">{{ __('clearance::ui.common.guard') }}</th>
                    <th class="px-4 py-3 text-left font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide text-xs">{{ __('clearance::ui.guards.driver') }}</th>
                    <th class="px-4 py-3 text-left font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide text-xs">{{ __('clearance::ui.guards.provider') }}</th>
                    <th class="px-4 py-3 text-left font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide text-xs">{{ __('clearance::ui.common.source') }}</th>
                    <th class="px-4 py-3 text-right font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide text-xs">{{ __('clearance::ui.common.actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                @forelse($guards as $name => $config)
                    <tr>
                        <td class="px-4 py-3 font-medium">
                            <div class="flex items-center gap-2">
                                @if($showIcons && !empty($guardMetas->get($name)?->icon_svg))
                                    <span class="inline-flex shrink-0 size-4" style="color: {{ \Rivalex\Clearance\Support\SvgSanitizer::safeCssColor($guardMetas->get($name)?->color) }}">{!! \Rivalex\Clearance\Support\SvgSanitizer::sanitize($guardMetas->get($name)->icon_svg) !!}</span>
                                @endif
                                <span class="font-mono text-xs">{{ $guardMetas->get($name)?->display_name ?: $name }}</span>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300">{{ $config['driver'] ?? '-' }}</td>
                        <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300">{{ $config['provider'] ?? '-' }}</td>
                        <td class="px-4 py-3">
                            @if(isset($config['is_db']))
                                <flux:badge size="sm" color="sky">{{ __('clearance::ui.guards.type_database') }}</flux:badge>
                            @else
                                <flux:badge size="sm" color="zinc">{{ __('clearance::ui.guards.type_config') }}</flux:badge>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
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
                        <td colspan="5" class="px-4 py-6 text-center text-zinc-400">
                            {{ $search ? __('clearance::ui.guards.no_match') : __('clearance::ui.guards.empty') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@assets
@once
    <link rel="stylesheet" href="{{ route('clearance.assets', 'css/clearance.min.css') }}">
@endonce
@endassets
