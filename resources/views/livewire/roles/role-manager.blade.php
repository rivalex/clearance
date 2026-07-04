<div class="clearance">
    <x-clearance::branding />
    {{-- Header --}}
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold">{{ __('clearance::ui.roles.title') }}</h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('clearance::ui.roles.description') }}</p>
        </div>
        <livewire:clearance::roles.new-role wire:key="new-role" />
    </div>

    <x-clearance::nav active="roles" />

    {{-- Search --}}
    <div class="mb-4">
        <flux:input
            wire:model.live.debounce.300ms="search"
            type="search"
            placeholder="{{ __('clearance::ui.roles.search_placeholder') }}"
            class="max-w-sm"
        >
            <x-slot name="icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="size-4"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
            </x-slot>
        </flux:input>
    </div>

    {{-- Roles table --}}
    <div class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
            <thead class="bg-gray-50 dark:bg-gray-900">
                <tr>
                    <th class="px-4 py-3 text-left font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide text-xs">{{ __('clearance::ui.common.role') }}</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide text-xs">{{ __('clearance::ui.common.guard') }}</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide text-xs">{{ __('clearance::ui.common.badges') }}</th>
                    <th class="px-4 py-3 text-center font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide text-xs">{{ __('clearance::ui.common.perms') }}</th>
                    <th class="px-4 py-3 text-center font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide text-xs">{{ __('clearance::ui.common.users') }}</th>
                    <th class="px-4 py-3 text-right font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide text-xs">{{ __('clearance::ui.common.actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse($roleData as $item)
                    <tr>
                        <td class="px-4 py-3 font-medium">
                            <div class="flex items-center gap-2">
                                @if($showIcons && !empty($item['clearance_meta']?->icon_svg))
                                    <div class="flex shrink-0 size-5 items-center" style="color: {{ \Rivalex\Clearance\Support\SvgSanitizer::safeCssColor($item['clearance_meta']?->color) }}">{!! \Rivalex\Clearance\Support\SvgSanitizer::sanitize($item['clearance_meta']->icon_svg) !!}</div>
                                @endif
                                <div class="flex items-baseline gap-2">
                                    <p>{{ \Illuminate\Support\Str::headline($item['clearance_meta']?->display_name ?: $item['role']->name) }}</p>
                                    <p class="text-gray-500 dark:text-gray-400 text-xs font-mono">{{ $item['clearance_meta']?->display_name ?: $item['role']->name }}</p>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-gray-500 dark:text-gray-400 text-xs font-mono">{{ $item['role']->guard_name }}</td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-1.5">
                                @if($item['meta']?->is_locked)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                                        {{ __('clearance::ui.common.locked') }}
                                    </span>
                                @endif
                            </div>
                        </td>
                        <td class="px-4 py-3 text-center text-xs text-gray-500 dark:text-gray-400">
                            {{ $item['permissions_count'] }}
                        </td>
                        <td class="px-4 py-3 text-center text-xs {{ $item['users_count'] > 0 ? 'font-semibold text-gray-700 dark:text-gray-200' : 'text-gray-400' }}">
                            {{ $item['users_count'] }}
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-2">
                                <livewire:clearance::roles.edit-role :roleId="$item['role']->id" :key="'edit-role-'.$item['role']->id" />
                                <livewire:clearance::roles.delete-role :roleId="$item['role']->id" :key="'delete-role-'.$item['role']->id" />
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-6 text-center text-gray-400">
                            @if($search !== '')
                                {{ __('clearance::ui.roles.no_match', ['search' => $search]) }}
                            @else
                                {{ __('clearance::ui.roles.empty') }}
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Pagination --}}
    @if($roleData->hasPages())
        <div class="mt-4">
            {{ $roleData->links() }}
        </div>
    @endif
</div>

@assets
@once
    <link rel="stylesheet" href="{{ route('clearance.assets', 'css/clearance.min.css') }}">
@endonce
@endassets
