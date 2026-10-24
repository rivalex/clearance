<div>
	<flux:modal.trigger name="{{ $modalName }}">
		<flux:button variant="ghost" size="xs"
		             icon="pencil-square">{{ __('clearance::ui.common.edit') }}</flux:button>
	</flux:modal.trigger>
	
	<flux:modal name="{{ $modalName }}" class="w-full md:w-[52rem] md:max-w-[52rem]">
		<livewire:clearance::permissions.permission-form
				:guard="$guard" :groupKey="$groupKey" :modalName="$modalName"
				:editingPrefix="$prefix" :key="$modalName"/>
	</flux:modal>
</div>
