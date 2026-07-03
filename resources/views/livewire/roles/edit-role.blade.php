<div class="clearance">
    <flux:modal.trigger name="{{ $modalName }}">
        <flux:button variant="ghost" size="xs" color="green" icon="pencil-square">{{ __('clearance::ui.common.edit') }}</flux:button>
    </flux:modal.trigger>

    <flux:modal name="{{ $modalName }}" class="md:w-[52rem]">
        <livewire:clearance::roles.role-form
                :roleId="$roleId"
                :modalName="$modalName"
                :key="$modalName" />
    </flux:modal>
</div>
