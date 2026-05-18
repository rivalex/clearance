<div class="clearance">
    <x-clearance::branding />
    {{-- Header --}}
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold">{{ __('clearance::ui.roles.title') }}</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ __('clearance::ui.roles.description') }}</p>
        </div>
        <livewire:clearance::roles.new-role wire:key="new-role" />
    </div>

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
    <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800">
        <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700 text-sm">
            <thead class="bg-zinc-50 dark:bg-zinc-900">
                <tr>
                    <th class="px-4 py-3 text-left font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide text-xs">{{ __('clearance::ui.common.role') }}</th>
                    <th class="px-4 py-3 text-left font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide text-xs">{{ __('clearance::ui.common.guard') }}</th>
                    <th class="px-4 py-3 text-left font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide text-xs">{{ __('clearance::ui.common.badges') }}</th>
                    <th class="px-4 py-3 text-center font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide text-xs">{{ __('clearance::ui.common.perms') }}</th>
                    <th class="px-4 py-3 text-center font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide text-xs">{{ __('clearance::ui.common.users') }}</th>
                    <th class="px-4 py-3 text-right font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide text-xs">{{ __('clearance::ui.common.actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                @forelse($roleData as $item)
                    <tr>
                        <td class="px-4 py-3 font-medium">
                            <div class="flex items-center gap-2">
                                @if($showIcons && !empty($item['clearance_meta']?->icon_svg))
                                    <span class="inline-flex shrink-0 size-4" style="color: {{ \Rivalex\Clearance\Support\SvgSanitizer::safeCssColor($item['clearance_meta']?->color) }}">{!! $item['clearance_meta']->icon_svg !!}</span>
                                @endif
                                {{ $item['clearance_meta']?->display_name ?: $item['role']->name }}
                            </div>
                        </td>
                        <td class="px-4 py-3 text-zinc-500 dark:text-zinc-400 text-xs font-mono">{{ $item['role']->guard_name }}</td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-1.5">
                                @if($item['meta']?->is_locked)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">
                                        {{ __('clearance::ui.common.locked') }}
                                    </span>
                                @endif
                            </div>
                        </td>
                        <td class="px-4 py-3 text-center text-xs text-zinc-500 dark:text-zinc-400">
                            {{ $item['permissions_count'] }}
                        </td>
                        <td class="px-4 py-3 text-center text-xs {{ $item['users_count'] > 0 ? 'font-semibold text-zinc-700 dark:text-zinc-200' : 'text-zinc-400' }}">
                            {{ $item['users_count'] }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-2">
                                <livewire:clearance::roles.edit-role :roleId="$item['role']->id" :key="'edit-role-'.$item['role']->id" />
                                <livewire:clearance::roles.delete-role :roleId="$item['role']->id" :key="'delete-role-'.$item['role']->id" />
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-6 text-center text-zinc-400">
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
