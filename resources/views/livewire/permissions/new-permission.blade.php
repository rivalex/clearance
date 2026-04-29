<div>
    <flux:button wire:click="$set('showModal', true)" variant="primary" color="green" size="sm" icon="plus">
        Add group
    </flux:button>

    <flux:modal wire:model="showModal" class="md:w-[42rem]">
        <div class="clearance">
            @if($showModal)
                <livewire:clearance-permission-form
                    :editingPrefix="''"
                    :key="'new-perm-form'"
                />
            @endif
        </div>
    </flux:modal>
</div>
