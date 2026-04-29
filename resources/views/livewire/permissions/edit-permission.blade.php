<div>
    <button wire:click="$set('showModal', true)"
            class="text-xs text-sky-600 dark:text-sky-400 hover:underline">Edit</button>

    <flux:modal wire:model="showModal" class="md:w-[42rem]">
        <div class="clearance">
            @if($showModal)
                <livewire:clearance-permission-form
                    :editingPrefix="$prefix"
                    :key="'edit-perm-'.$prefix"
                />
            @endif
        </div>
    </flux:modal>
</div>
