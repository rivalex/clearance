<div>
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold">User Role Contexts</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                @if($scopeContextType)
                    Scoped to context: <span class="font-mono text-xs">{{ $scopeContextType }}#{{ $scopeContextId }}</span>
                @else
                    All contextual role assignments.
                @endif
            </p>
        </div>
        @unless($showAssignForm)
            <button wire:click="$set('showAssignForm', true)"
                    class="px-4 py-2 text-sm font-medium bg-zinc-900 dark:bg-white text-white dark:text-zinc-900 rounded-lg hover:opacity-90 transition">
                Assign role
            </button>
        @endunless
    </div>

    @if($errorMessage)
        <div class="mb-4 rounded-md bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 px-4 py-3 text-sm text-red-700 dark:text-red-400">
            {{ $errorMessage }}
        </div>
    @endif

    @if($showAssignForm)
        <div class="mb-6 rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-5">
            <h2 class="text-sm font-semibold mb-3">Assign contextual role</h2>
            <div class="grid grid-cols-2 gap-4 mb-4 sm:grid-cols-4">
                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-1">User ID</label>
                    <input wire:model="assignUserId" type="text" placeholder="e.g. 42"
                           class="w-full rounded-md border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-900 px-3 py-2 text-sm" />
                </div>
                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-1">Role</label>
                    <select wire:model="assignRoleId"
                            class="w-full rounded-md border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-900 px-3 py-2 text-sm">
                        <option value="">— select —</option>
                        @foreach($availableRoles as $role)
                            <option value="{{ $role->id }}">{{ $role->name }}</option>
                        @endforeach
                    </select>
                </div>
                @if(!$scopeContextType)
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-1">Context type</label>
                        <input wire:model="assignContextType" type="text" placeholder="App\Models\Project"
                               class="w-full rounded-md border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-900 px-3 py-2 text-sm font-mono text-xs" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-1">Context ID</label>
                        <input wire:model="assignContextId" type="text" placeholder="1"
                               class="w-full rounded-md border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-900 px-3 py-2 text-sm" />
                    </div>
                @else
                    <div class="col-span-2">
                        <label class="block text-xs font-medium text-zinc-500 mb-1">Context (locked to your scope)</label>
                        <span class="inline-block px-3 py-2 text-xs font-mono bg-zinc-100 dark:bg-zinc-700 rounded-md text-zinc-600 dark:text-zinc-300">
                            {{ $scopeContextType }}#{{ $scopeContextId }}
                        </span>
                    </div>
                @endif
            </div>
            <div class="flex gap-3">
                <button wire:click="assign"
                        class="px-4 py-2 text-sm font-medium bg-zinc-900 dark:bg-white text-white dark:text-zinc-900 rounded-lg hover:opacity-90 transition">
                    Assign
                </button>
                <button wire:click="$set('showAssignForm', false)"
                        class="text-sm text-zinc-500 hover:underline">Cancel</button>
            </div>
        </div>
    @endif

    <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800">
        <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700 text-sm">
            <thead class="bg-zinc-50 dark:bg-zinc-900">
                <tr>
                    <th class="px-4 py-3 text-left font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide text-xs">User ID</th>
                    <th class="px-4 py-3 text-left font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide text-xs">Role</th>
                    <th class="px-4 py-3 text-left font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide text-xs">Context</th>
                    <th class="px-4 py-3 text-right font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide text-xs">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                @forelse($assignments as $assignment)
                    <tr>
                        <td class="px-4 py-3 font-mono text-xs">{{ $assignment->user_id }}</td>
                        <td class="px-4 py-3">{{ $assignment->role?->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-xs text-zinc-500 dark:text-zinc-400 font-mono">
                            {{ $assignment->context_type }}#{{ $assignment->context_id }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            <button wire:click="revoke({{ $assignment->id }})"
                                    wire:confirm="Revoke this context assignment?"
                                    class="text-xs text-red-600 dark:text-red-400 hover:underline">Revoke</button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-6 text-center text-zinc-400">No contextual role assignments.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
