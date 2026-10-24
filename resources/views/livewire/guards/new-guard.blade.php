<div>
    @if($canCreate)
    <flux:modal.trigger name="new-guard-modal">
        <flux:button variant="primary" size="sm" icon="plus">{{ __('clearance::ui.common.add_guard') }}</flux:button>
    </flux:modal.trigger>

    <flux:modal name="new-guard-modal" class="w-full md:w-[32rem]">
        <div>
            <livewire:clearance::guards.guard-form
                    :modalName="'new-guard-modal'"
                    :key="'new-guard-modal'" />
        </div>
    </flux:modal>
    @endif
</div>
