<div>
    <flux:modal.trigger name="new-role-modal">
        <flux:button variant="primary" size="sm">
            <div class="flex items-center gap-2">
                <svg class="size-4" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                {{ __('clearance::ui.common.add_role') }}
            </div>
        </flux:button>
    </flux:modal.trigger>

    <flux:modal name="new-role-modal" class="md:w-[52rem]! md:max-w-[52rem]!">
        <div class="clearance">
            <livewire:clearance::roles.role-form
                :roleId="null"
                :modalName="'new-role-modal'"
                :key="'new-role-modal'" />
        </div>
    </flux:modal>
</div>
