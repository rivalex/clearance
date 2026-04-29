<div class="clearance">
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
        <flux:button wire:click="openAssignForm" variant="primary" size="sm" icon="plus">
            Assign role
        </flux:button>
    </div>

    @if($errorMessage)
        <div class="mb-4 rounded-md bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 px-4 py-3 text-sm text-red-700 dark:text-red-400">
            {{ $errorMessage }}
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

    {{-- Assign role modal --}}
    <flux:modal wire:model="showAssignForm" class="md:w-[38rem]">
        <div class="clearance space-y-5">
            <div>
                <flux:heading size="lg">Assign contextual role</flux:heading>
                <flux:text class="text-zinc-500 dark:text-zinc-400">
                    Assign a role to a user within a specific context.
                </flux:text>
            </div>

            @if($errorMessage)
                <div class="rounded-md bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 px-4 py-3 text-sm text-red-700 dark:text-red-400">
                    {{ $errorMessage }}
                </div>
            @endif

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <flux:input wire:model="assignUserId" label="User ID" placeholder="e.g. 42" />
                <flux:select wire:model="assignRoleId" label="Role">
                    <option value="">— select —</option>
                    @foreach($availableRoles as $role)
                        <option value="{{ $role->id }}">{{ $role->name }}</option>
                    @endforeach
                </flux:select>
            </div>

            @if(!$scopeContextType)
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <flux:input wire:model="assignContextType" label="Context type" placeholder="App\Models\Project" class="font-mono" />
                    <flux:input wire:model="assignContextId" label="Context ID" placeholder="1" />
                </div>
            @else
                <div>
                    <flux:text class="text-xs font-medium text-zinc-500 mb-1">Context (locked to your scope)</flux:text>
                    <span class="inline-block px-3 py-2 text-xs font-mono bg-zinc-100 dark:bg-zinc-700 rounded-md text-zinc-600 dark:text-zinc-300">
                        {{ $scopeContextType }}#{{ $scopeContextId }}
                    </span>
                </div>
            @endif

            <div class="flex items-center justify-between pt-2">
                <flux:button wire:click="closeAssignForm" variant="ghost">Cancel</flux:button>
                <flux:button wire:click="assign" variant="primary">Assign</flux:button>
            </div>
        </div>
    </flux:modal>
</div>

@assets
@once
    <link rel="stylesheet" href="{{ route('clearance.assets', 'css/clearance.min.css') }}">
@endonce
@endassets
