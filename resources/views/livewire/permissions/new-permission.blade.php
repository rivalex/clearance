<div>
	<flux:modal.trigger name="new-permission-modal">
		<flux:button variant="primary" size="sm">
			<div class="flex items-center gap-2">
				<svg class="size-4" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
				     fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
					<path d="M5 12h14"/>
					<path d="M12 5v14"/>
				</svg>
				<p>{{ __('clearance::ui.common.add_group') }}</p>
			</div>
		</flux:button>
	</flux:modal.trigger>
	
	<flux:modal name="new-permission-modal" class="min-w-96 md:min-w-2xl">
		<livewire:clearance::permissions.permission-form
				:modalName="'new-permission-modal'"
				:key="'new-permission-modal'"/>
	</flux:modal>
</div>
