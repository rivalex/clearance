<div class="clearance">
    {{-- Header --}}
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold">Roles</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Manage roles and their permission assignments.</p>
        </div>
        <flux:button wire:click="create" variant="primary" size="sm" icon="plus">
            Add role
        </flux:button>
    </div>

    {{-- Search --}}
    <div class="mb-4">
        <flux:input
            wire:model.live.debounce.300ms="search"
            type="search"
            placeholder="Search roles…"
            class="max-w-sm"
            icon="magnifying-glass"
        />
    </div>

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
                    <tr>
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

    {{-- Create / Edit modal --}}
    <flux:modal
        name="role-form"
        class="md:w-[52rem]"
        x-on:open-role-form.window="show()"
        x-on:close-role-form.window="close()"
    >
        <div class="clearance">
            @if($showForm)
                <livewire:clearance-role-form
                    :roleId="$editingId"
                    :key="'rf-'.($editingId ?? 'new')"
                />
            @endif
        </div>
    </flux:modal>

    {{-- Delete confirmation modal --}}
    <flux:modal
        name="delete-role"
        class="md:w-[32rem]"
        x-on:open-delete-role.window="show()"
        x-on:close-delete-role.window="close()"
    >
        <div class="clearance">
            @if($deletingRoleId !== null && $deletingRole !== null)
                @if($deletingRoleUsersCount > 0)
                    <flux:heading size="lg" class="mb-1">Cannot delete role</flux:heading>
                    <flux:text class="mb-4 text-zinc-500 dark:text-zinc-400">
                        Role <code class="font-mono font-semibold text-zinc-700 dark:text-zinc-200">{{ $deletingRole->name }}</code>
                        has {{ $deletingRoleUsersCount }} user(s) assigned.
                        Reassign or remove them before deleting.
                    </flux:text>
                    <div class="flex justify-end">
                        <flux:button wire:click="cancelDelete" variant="ghost">Close</flux:button>
                    </div>
                @else
                    <flux:heading size="lg" class="mb-1">Delete role?</flux:heading>
                    <flux:text class="mb-4 text-zinc-500 dark:text-zinc-400">
                        This action cannot be undone. Type
                        <span class="font-mono font-semibold text-zinc-700 dark:text-zinc-200">DELETE {{ $deletingRole->name }}</span>
                        to confirm.
                    </flux:text>
                    <flux:input
                        wire:model="deleteConfirmText"
                        placeholder="DELETE {{ $deletingRole->name }}"
                        class="font-mono mb-4"
                    />
                    <div class="flex items-center justify-between">
                        <flux:button wire:click="cancelDelete" variant="ghost">Cancel</flux:button>
                        <flux:button wire:click="confirmDelete" variant="danger">Confirm delete</flux:button>
                    </div>
                @endif
            @endif
        </div>
    </flux:modal>
</div>

@assets
@once
    <link rel="stylesheet" href="{{ route('clearance.assets', 'css/clearance.min.css') }}">
@endonce
@endassets
