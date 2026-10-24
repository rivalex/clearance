<div>
    @if($canCreate)
    <flux:modal.trigger name="new-role-modal">
        <flux:button variant="primary" size="sm" icon="plus">{{ __('clearance::ui.common.add_role') }}</flux:button>
    </flux:modal.trigger>

    <flux:modal name="new-role-modal" class="w-full md:w-[52rem] md:max-w-[52rem]">
        <div>
            <livewire:clearance::roles.role-form
                :roleId="null"
                :modalName="'new-role-modal'"
                :key="'new-role-modal'" />
        </div>
    </flux:modal>
    @endif
</div>
