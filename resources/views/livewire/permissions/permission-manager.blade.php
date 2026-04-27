<div>
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold">Permissions</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Manage application permissions.</p>
        </div>
        @unless($showForm)
            <button wire:click="create"
                    class="px-4 py-2 text-sm font-medium bg-zinc-900 dark:bg-white text-white dark:text-zinc-900 rounded-lg hover:opacity-90 transition">
                Add permission
            </button>
        @endunless
    </div>

    @if($showForm)
        <div class="mb-6">
            <livewire:clearance-permission-form :permissionId="$editingId" :key="'pf-'.($editingId ?? 'new')" />
        </div>
    @endif

    <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800">
        <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700 text-sm">
            <thead class="bg-zinc-50 dark:bg-zinc-900">
                <tr>
                    <th class="px-4 py-3 text-left font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide text-xs">Name</th>
                    <th class="px-4 py-3 text-left font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide text-xs">Guard</th>
                    <th class="px-4 py-3 text-right font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide text-xs">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                @forelse($permissions as $permission)
                    @php
                        $sep   = config('clearance.naming_separator', '-');
                        $group = explode($sep, $permission->name)[0];
                        $color = $this->colorForGroup($group);
                    @endphp
                    <tr>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                                             bg-{{ $color }}-100 text-{{ $color }}-700
                                             dark:bg-{{ $color }}-900/30 dark:text-{{ $color }}-400">
                                    {{ $group }}
                                </span>
                                <span class="font-mono text-xs">{{ $permission->name }}</span>
                                <button
                                    type="button"
                                    title="Copy to clipboard"
                                    onclick="navigator.clipboard.writeText('{{ $permission->name }}')"
                                    class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200 transition text-xs"
                                >⎘</button>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-zinc-500 dark:text-zinc-400 text-xs font-mono">{{ $permission->guard_name }}</td>
                        <td class="px-4 py-3 text-right space-x-2">
                            <button wire:click="edit({{ $permission->id }})"
                                    class="text-xs text-sky-600 dark:text-sky-400 hover:underline">Edit</button>
                            <button wire:click="delete({{ $permission->id }})"
                                    wire:confirm="Delete permission '{{ $permission->name }}'?"
                                    class="text-xs text-red-600 dark:text-red-400 hover:underline">Delete</button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="px-4 py-6 text-center text-zinc-400">No permissions defined yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
