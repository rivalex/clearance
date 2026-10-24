<div>
    <x-clearance::branding />
    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <div class="min-w-0">
            <h1 class="text-xl font-semibold">{{ __('clearance::ui.guards.title') }}</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ __('clearance::ui.guards.description') }}</p>
        </div>
        <livewire:clearance::guards.new-guard />
    </div>

    <x-clearance::nav active="guards" />

    <div class="mb-4">
        <flux:input wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('clearance::ui.guards.search_placeholder') }}"
                    class="max-w-sm"
                    clearable />
    </div>

    <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800">
        <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700 text-sm">
            <thead class="bg-zinc-50 dark:bg-zinc-900">
                <tr>
                    <th scope="col" class="whitespace-nowrap px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('clearance::ui.common.guard') }}</th>
                    <th scope="col" class="whitespace-nowrap px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('clearance::ui.guards.driver') }}</th>
                    <th scope="col" class="whitespace-nowrap px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('clearance::ui.guards.provider') }}</th>
                    <th scope="col" class="whitespace-nowrap px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('clearance::ui.common.source') }}</th>
                    <th scope="col" class="whitespace-nowrap px-4 py-3 text-right text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('clearance::ui.common.actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                @forelse($guards as $name => $config)
                    <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-700/50">
                        <td class="px-4 py-3 font-medium">
                            <div class="flex items-center gap-2">
                                @if($showIcons && !empty($guardMetas->get($name)?->icon_svg))
                                    <div class="inline-flex shrink-0 size-5 items-center" style="color: {{ \Rivalex\Clearance\Support\SvgSanitizer::safeCssColor($guardMetas->get($name)?->color) }}">{!! \Rivalex\Clearance\Support\SvgSanitizer::sanitize($guardMetas->get($name)->icon_svg) !!}</div>
                                @endif
                                <div class="flex items-baseline gap-2">
                                    <p class="max-w-xs truncate" title="{{ \Illuminate\Support\Str::headline($guardMetas->get($name)?->display_name ?: $name) }}">{{ \Illuminate\Support\Str::headline($guardMetas->get($name)?->display_name ?: $name) }}</p>
                                    <p title="{{ $name }}" class="max-w-xs truncate text-zinc-500 dark:text-zinc-400 text-xs font-mono">{{ $name }}</p>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3 font-mono text-xs text-zinc-600 dark:text-zinc-300">{{ $config['driver'] ?? '-' }}</td>
                        <td class="px-4 py-3 font-mono text-xs text-zinc-600 dark:text-zinc-300">{{ $config['provider'] ?? '-' }}</td>
                        <td class="px-4 py-3">
                            @if(isset($config['is_db']))
                                <flux:badge size="sm" color="zinc">{{ __('clearance::ui.guards.type_database') }}</flux:badge>
                            @else
                                <flux:badge size="sm" color="zinc">{{ __('clearance::ui.guards.type_config') }}</flux:badge>
                            @endif
                        </td>
                        <td class="whitespace-nowrap px-4 py-3">
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
                        <td colspan="5" class="px-4 py-8 text-center text-sm text-zinc-500 dark:text-zinc-400">
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
@endonce
@endassets
