<div>
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold">Roles</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Manage roles and their permission assignments.</p>
        </div>
        @unless($showForm)
            <button wire:click="create"
                    class="px-4 py-2 text-sm font-medium bg-zinc-900 dark:bg-white text-white dark:text-zinc-900 rounded-lg hover:opacity-90 transition">
                Add role
            </button>
        @endunless
    </div>

    @if($showForm)
        <div class="mb-6">
            <livewire:clearance-role-form :roleId="$editingId" :key="'rf-'.($editingId ?? 'new')" />
        </div>
    @endif

    <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800">
        <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700 text-sm">
            <thead class="bg-zinc-50 dark:bg-zinc-900">
                <tr>
                    <th class="px-4 py-3 text-left font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide text-xs">Role</th>
                    <th class="px-4 py-3 text-left font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide text-xs">Guard</th>
                    <th class="px-4 py-3 text-left font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide text-xs">Badges</th>
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
                        <td class="px-4 py-3 text-right space-x-2">
                            <button wire:click="edit({{ $item['role']->id }})"
                                    class="text-xs text-sky-600 dark:text-sky-400 hover:underline">Edit</button>
                            @unless($item['meta']?->is_protected)
                                <button wire:click="delete({{ $item['role']->id }})"
                                        wire:confirm="Delete role '{{ $item['role']->name }}'?"
                                        class="text-xs text-red-600 dark:text-red-400 hover:underline">Delete</button>
                            @endunless
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-6 text-center text-zinc-400">No roles defined yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
