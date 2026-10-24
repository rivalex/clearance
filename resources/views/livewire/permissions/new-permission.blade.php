<div>
	@if($canCreate)
	<flux:modal.trigger name="new-permission-modal">
		<flux:button variant="primary" size="sm" icon="plus">{{ __('clearance::ui.common.add_group') }}</flux:button>
	</flux:modal.trigger>
	
	<flux:modal name="new-permission-modal" class="w-full md:w-[52rem] md:max-w-[52rem]">
		<livewire:clearance::permissions.permission-form
				:modalName="'new-permission-modal'"
				:key="'new-permission-modal'"/>
	</flux:modal>
	@endif
</div>
