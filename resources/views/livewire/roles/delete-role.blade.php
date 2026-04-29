<div>
    <button wire:click="$set('showModal', true)"
            class="text-xs text-red-600 dark:text-red-400 hover:underline">Delete</button>

    <flux:modal wire:model="showModal" class="md:w-[32rem]">
        <div class="clearance space-y-4">
            @if($role !== null)
                @if($usersCount > 0)
                    <flux:heading size="lg">Cannot delete role</flux:heading>
                    <flux:text class="text-zinc-500 dark:text-zinc-400">
                        Role <code class="font-mono font-semibold text-zinc-700 dark:text-zinc-200">{{ $role->name }}</code>
                        has {{ $usersCount }} user(s) assigned.
                        Reassign or remove them before deleting.
                    </flux:text>
                    <div class="flex justify-end">
                        <flux:button wire:click="$set('showModal', false)" variant="ghost">Close</flux:button>
                    </div>
                @else
                    <flux:heading size="lg">Delete role?</flux:heading>
                    <flux:text class="text-zinc-500 dark:text-zinc-400">
                        This cannot be undone. Type
                        <span class="font-mono font-semibold text-zinc-700 dark:text-zinc-200">DELETE {{ $role->name }}</span>
                        to confirm.
                    </flux:text>
                    <flux:input wire:model="confirmText" placeholder="DELETE {{ $role->name }}" class="font-mono" />
                    <div class="flex items-center justify-between pt-1">
                        <flux:button wire:click="$set('showModal', false)" variant="ghost">Cancel</flux:button>
                        <flux:button wire:click="confirmDelete" variant="danger">Confirm delete</flux:button>
                    </div>
                @endif
            @endif
        </div>
    </flux:modal>
</div>
