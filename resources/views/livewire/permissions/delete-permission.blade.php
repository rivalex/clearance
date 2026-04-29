<div>
    <button wire:click="$set('showModal', true)"
            class="text-xs text-red-600 dark:text-red-400 hover:underline">Delete</button>

    <flux:modal wire:model="showModal" class="md:w-[30rem]">
        <div class="clearance space-y-4">
            <flux:heading size="lg">Delete permission group?</flux:heading>
            <flux:text class="text-zinc-500 dark:text-zinc-400">
                All permissions in group
                <code class="font-mono font-semibold text-zinc-700 dark:text-zinc-200">{{ $prefix }}</code>
                will be permanently removed. Type
                <span class="font-mono font-semibold">DELETE {{ $prefix }}</span> to confirm.
            </flux:text>

            <flux:input
                wire:model="confirmText"
                placeholder="DELETE {{ $prefix }}"
                class="font-mono"
            />

            <div class="flex items-center justify-between pt-1">
                <flux:button wire:click="$set('showModal', false)" variant="ghost">Cancel</flux:button>
                <flux:button wire:click="confirmDelete" variant="danger">Confirm delete</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
