<div class="clearance">
    {{-- Header --}}
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold">Roles</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Manage roles and their permission assignments.</p>
        </div>
        @unless($showForm || $deletingRoleId !== null)
            <button wire:click="create"
                    class="px-4 py-2 text-sm font-medium bg-zinc-900 dark:bg-white text-white dark:text-zinc-900 rounded-lg hover:opacity-90 transition">
                Add role
            </button>
        @endunless
    </div>

    {{-- Role form (create / edit) --}}
    @if($showForm)
        <div class="mb-6">
            <livewire:clearance-role-form :roleId="$editingId" :key="'rf-'.($editingId ?? 'new')" />
        </div>
    @endif

    {{-- Typed-delete confirmation panel (T25) --}}
    @if($deletingRoleId !== null && $deletingRole !== null)
        <div class="mb-6 rounded-lg border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/20 p-4">
            @if($deletingRoleUsersCount > 0)
                <p class="text-sm font-medium text-red-700 dark:text-red-400 mb-1">
                    Cannot delete role <code class="font-mono font-bold">{{ $deletingRole->name }}</code>.
                </p>
                <p class="text-sm text-red-600 dark:text-red-400 mb-3">
                    {{ $deletingRoleUsersCount }} user(s) are currently assigned this role.
                    Reassign or remove them before deleting.
                </p>
            @else
                <p class="text-sm font-medium text-red-700 dark:text-red-400 mb-1">
                    Delete role <code class="font-mono font-bold">{{ $deletingRole->name }}</code>?
                    This action cannot be undone.
                </p>
                <p class="text-xs text-red-500 dark:text-red-400 mb-3">
                    Type <span class="font-mono font-semibold">DELETE {{ $deletingRole->name }}</span> to confirm.
                </p>
                <div class="flex items-center gap-3 mb-3">
                    <input wire:model="deleteConfirmText"
                           type="text"
                           placeholder="DELETE {{ $deletingRole->name }}"
                           class="rounded-md border border-red-300 dark:border-red-700 bg-white dark:bg-zinc-900
                                  px-3 py-1.5 text-sm font-mono w-64 focus:outline-none focus:ring-2 focus:ring-red-400" />
                    <button wire:click="confirmDelete"
                            class="px-3 py-1.5 text-sm font-medium bg-red-600 text-white rounded-lg hover:bg-red-700 transition">
                        Confirm delete
                    </button>
                </div>
            @endif
            <button wire:click="cancelDelete"
                    class="text-sm text-zinc-500 dark:text-zinc-400 hover:underline">
                Cancel
            </button>
        </div>
    @endif

    {{-- Search --}}
    @unless($showForm)
        <div class="mb-4">
            <input wire:model.live.debounce.300ms="search"
                   type="search"
                   placeholder="Search roles…"
                   class="w-full max-w-sm rounded-md border border-zinc-300 dark:border-zinc-600
                          bg-white dark:bg-zinc-900 px-3 py-2 text-sm
                          focus:outline-none focus:ring-2 focus:ring-zinc-400" />
        </div>
    @endunless

    {{-- Roles table --}}
    <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800">
        <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700 text-sm">
            <thead class="bg-zinc-50 dark:bg-zinc-900">
                <tr>
                    <th class="px-4 py-3 text-left font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide text-xs">Role</th>
                    <th class="px-4 py-3 text-left font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide text-xs">Guard</th>
                    <th class="px-4 py-3 text-left font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide text-xs">Badges</th>
                    <th class="px-4 py-3 text-center font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide text-xs">Perms</th>
                    <th class="px-4 py-3 text-center font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide text-xs">Users</th>
                    <th class="px-4 py-3 text-right font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide text-xs">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                @forelse($roleData as $item)
                    <tr class="{{ $deletingRoleId === $item['role']->id ? 'bg-red-50 dark:bg-red-900/10' : '' }}">
                        <td class="px-4 py-3 font-medium">{{ $item['role']->name }}</td>
                        <td class="px-4 py-3 text-zinc-500 dark:text-zinc-400 text-xs font-mono">{{ $item['role']->guard_name }}</td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-1.5">
                                @if($item['meta']?->is_system)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-violet-100 text-violet-700 dark:bg-violet-900/30 dark:text-violet-400">
                                        system
                                    </span>
                                @endif
                                @if($item['meta']?->is_protected)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">
                                        protected
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
                        <td class="px-4 py-3 text-right space-x-2">
                            <button wire:click="edit({{ $item['role']->id }})"
                                    class="text-xs text-sky-600 dark:text-sky-400 hover:underline">Edit</button>
                            @unless($item['meta']?->is_protected)
                                <button wire:click="delete({{ $item['role']->id }})"
                                        class="text-xs text-red-600 dark:text-red-400 hover:underline">Delete</button>
                            @endunless
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-6 text-center text-zinc-400">
                            @if($search !== '')
                                No roles matching "{{ $search }}".
                            @else
                                No roles defined yet.
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
