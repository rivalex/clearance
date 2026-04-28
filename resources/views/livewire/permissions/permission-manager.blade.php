<div class="clearance">
    {{-- Header --}}
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold">Permissions</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Manage application permissions grouped by prefix.</p>
        </div>
        @unless($showForm || $deletingPrefix !== '')
            <button wire:click="create"
                    class="px-4 py-2 text-sm font-medium bg-zinc-900 dark:bg-white text-white dark:text-zinc-900 rounded-lg hover:opacity-90 transition">
                Add group
            </button>
        @endunless
    </div>

    {{-- Permission form (create / edit) --}}
    @if($showForm)
        <div class="mb-6">
            <livewire:clearance-permission-form :editingPrefix="$editingPrefix" :key="'pf-'.($editingPrefix ?: 'new')" />
        </div>
    @endif

    {{-- Typed-delete confirmation panel (T25) --}}
    @if($deletingPrefix !== '')
        <div class="mb-6 rounded-lg border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/20 p-4">
            <p class="text-sm font-medium text-red-700 dark:text-red-400 mb-1">
                Delete group <code class="font-mono font-bold">{{ $deletingPrefix }}</code>?
                All permissions in this group will be permanently removed.
            </p>
            <p class="text-xs text-red-500 dark:text-red-400 mb-3">
                Type <span class="font-mono font-semibold">DELETE {{ $deletingPrefix }}</span> to confirm.
            </p>
            <div class="flex items-center gap-3">
                <input wire:model="deleteConfirmText"
                       type="text"
                       placeholder="DELETE {{ $deletingPrefix }}"
                       class="rounded-md border border-red-300 dark:border-red-700 bg-white dark:bg-zinc-900
                              px-3 py-1.5 text-sm font-mono w-64 focus:outline-none focus:ring-2 focus:ring-red-400" />
                <button wire:click="confirmDeleteGroup"
                        class="px-3 py-1.5 text-sm font-medium bg-red-600 text-white rounded-lg hover:bg-red-700 transition">
                    Confirm delete
                </button>
                <button wire:click="cancelDelete"
                        class="text-sm text-zinc-500 dark:text-zinc-400 hover:underline">
                    Cancel
                </button>
            </div>
        </div>
    @endif

    {{-- Search --}}
    @unless($showForm)
        <div class="mb-4">
            <input wire:model.live.debounce.300ms="search"
                   type="search"
                   placeholder="Search by prefix or permission name…"
                   class="w-full max-w-sm rounded-md border border-zinc-300 dark:border-zinc-600
                          bg-white dark:bg-zinc-900 px-3 py-2 text-sm
                          focus:outline-none focus:ring-2 focus:ring-zinc-400" />
        </div>
    @endunless

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
                    <div class="flex items-center gap-3">
                        <button wire:click="edit('{{ $prefix }}')"
                                class="text-xs text-sky-600 dark:text-sky-400 hover:underline">Edit</button>
                        <button wire:click="delete('{{ $prefix }}')"
                                class="text-xs text-red-600 dark:text-red-400 hover:underline">Delete</button>
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
