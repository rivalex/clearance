<div class="clearance">
    <flux:modal.trigger name="{{ $modalName }}">
        <flux:button variant="ghost" size="xs" color="green" icon="pencil-square">{{ __('clearance::ui.common.edit') }}</flux:button>
    </flux:modal.trigger>

    <flux:modal name="{{ $modalName }}" class="md:w-[32rem]">
        <livewire:clearance::guards.guard-form
                :guardId="$guardId"
                :modalName="$modalName"
                :key="$modalName" />
    </flux:modal>
</div>
