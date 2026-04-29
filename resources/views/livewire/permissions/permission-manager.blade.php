<div class="clearance">
    {{-- Header --}}
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold">Permissions</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Manage application permissions grouped by prefix.</p>
        </div>
        <livewire:clearance-new-permission wire:key="new-permission" />
    </div>

    {{-- Search --}}
    <div class="mb-4">
        <flux:input
            wire:model.live.debounce.300ms="search"
            type="search"
            placeholder="Search by prefix or permission name…"
            class="max-w-sm"
            icon="magnifying-glass"
        />
    </div>

    {{-- Grouped permission cards --}}
    <div class="space-y-3">
        @forelse($groupedPermissions as $prefix => $permissions)
            @php
                $color = $this->colorForGroup($prefix);
                $sep   = config('clearance.naming_separator', '-');
            @endphp
            <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800">
                {{-- Group header row --}}
                <div class="flex items-center justify-between px-4 py-2.5 border-b border-zinc-100 dark:border-zinc-700">
                    <div class="flex items-center gap-2">
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold
                                     bg-{{ $color }}-100 text-{{ $color }}-700
                                     dark:bg-{{ $color }}-900/30 dark:text-{{ $color }}-400">
                            {{ $prefix }}
                        </span>
                        <span class="text-xs text-zinc-400">{{ count($permissions) }} permission(s)</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <livewire:clearance-edit-permission :prefix="$prefix" :wire:key="'edit-'.$prefix" />
                        <livewire:clearance-delete-permission :prefix="$prefix" :wire:key="'delete-'.$prefix" />
                    </div>
                </div>
                {{-- Ability badges --}}
                <div class="px-4 py-3 flex flex-wrap gap-2">
                    @foreach($permissions as $permission)
                        @php
                            $ability   = substr($permission->name, strlen($prefix) + strlen($sep));
                            $badgeType = $this->badgeTypeForAbility($ability);
                        @endphp
                        <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs font-medium
                            @if($badgeType === 'destructive')
                                bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400
                            @elseif($badgeType === 'crud')
                                bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400
                            @else
                                bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400
                            @endif
                        ">
                            {{ $ability }}
                            <button type="button"
                                    title="Copy {{ $permission->name }}"
                                    onclick="navigator.clipboard.writeText('{{ $permission->name }}')"
                                    class="opacity-50 hover:opacity-100 transition leading-none">⎘</button>
                        </span>
                    @endforeach
                </div>
            </div>
        @empty
            <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 px-4 py-8 text-center text-sm text-zinc-400">
                @if($search !== '')
                    No groups matching "<span class="font-mono">{{ $search }}</span>".
                @else
                    No permissions defined yet.
                @endif
            </div>
        @endforelse
    </div>

    {{-- Pagination --}}
    @if($groupedPermissions->hasPages())
        <div class="mt-4">
            {{ $groupedPermissions->links() }}
        </div>
    @endif
</div>

@assets
@once
    <link rel="stylesheet" href="{{ route('clearance.assets', 'css/clearance.min.css') }}">
@endonce
@endassets
