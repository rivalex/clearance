<div class="clearance">
    {{-- Header --}}
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold">Permissions</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Manage application permissions grouped by prefix.</p>
        </div>
        <flux:button wire:click="create" variant="primary" size="sm" icon="plus">
            Add group
        </flux:button>
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

    {{-- Create / Edit modal --}}
    <flux:modal
        name="permission-form"
        class="md:w-[42rem]"
        x-on:open-permission-form.window="show()"
        x-on:close-permission-form.window="close()"
    >
        <div class="clearance">
            @if($showForm)
                <livewire:clearance-permission-form
                    :editingPrefix="$editingPrefix"
                    :key="'pf-'.($editingPrefix ?: 'new')"
                />
            @endif
        </div>
    </flux:modal>

    {{-- Delete confirmation modal --}}
    <flux:modal
        name="delete-permission"
        class="md:w-[30rem]"
        x-on:open-delete-permission.window="show()"
        x-on:close-delete-permission.window="close()"
    >
        <div class="clearance">
            @if($deletingPrefix !== '')
                <flux:heading size="lg" class="mb-1">Delete permission group?</flux:heading>
                <flux:text class="mb-4 text-zinc-500 dark:text-zinc-400">
                    All permissions in group
                    <code class="font-mono font-semibold text-zinc-700 dark:text-zinc-200">{{ $deletingPrefix }}</code>
                    will be permanently removed. Type
                    <span class="font-mono font-semibold">DELETE {{ $deletingPrefix }}</span> to confirm.
                </flux:text>

                <flux:input
                    wire:model="deleteConfirmText"
                    placeholder="DELETE {{ $deletingPrefix }}"
                    class="font-mono mb-4"
                />

                <div class="flex items-center justify-between">
                    <flux:button wire:click="cancelDelete" variant="ghost">Cancel</flux:button>
                    <flux:button wire:click="confirmDeleteGroup" variant="danger">Confirm delete</flux:button>
                </div>
            @endif
        </div>
    </flux:modal>
</div>

@assets
@once
    <link rel="stylesheet" href="{{ route('clearance.assets', 'css/clearance.min.css') }}">
@endonce
@endassets
