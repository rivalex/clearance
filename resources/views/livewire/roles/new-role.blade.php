<div>
    <flux:button wire:click="$set('showModal', true)" variant="primary" size="sm" icon="plus">
        Add role
    </flux:button>

    <flux:modal wire:model="showModal" class="md:w-[52rem]">
        <div class="clearance">
            @if($showModal)
                <livewire:clearance-role-form
                    :roleId="null"
                    :key="'new-role-form'"
                />
            @endif
        </div>
    </flux:modal>
</div>
