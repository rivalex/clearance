<div>
    <flux:modal.trigger name="new-guard-modal">
        <flux:button variant="primary" color="green" size="sm">
            <div class="flex items-center gap-2">
                <svg class="size-4" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                {{ __('clearance::ui.common.add_guard') }}
            </div>
        </flux:button>
    </flux:modal.trigger>

    <flux:modal name="new-guard-modal" class="md:w-[32rem]">
        <div class="clearance">
            <livewire:clearance::guards.guard-form
                    :modalName="'new-guard-modal'"
                    :key="'new-guard-modal'" />
        </div>
    </flux:modal>
</div>
