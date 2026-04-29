<div>
    <button wire:click="$set('showModal', true)"
            class="text-xs text-sky-600 dark:text-sky-400 hover:underline">Edit</button>

    <flux:modal wire:model="showModal" class="md:w-[52rem]">
        <div class="clearance">
            @if($showModal)
                <livewire:clearance-role-form
                    :roleId="$roleId"
                    :key="'edit-role-'.$roleId"
                />
            @endif
        </div>
    </flux:modal>
</div>
