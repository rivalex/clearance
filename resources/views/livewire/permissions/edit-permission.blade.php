<x-clearance::dark-scope>
	<flux:modal.trigger name="{{ $modalName }}">
		<flux:button variant="ghost" size="xs"
		             icon="pencil-square">{{ __('clearance::ui.common.edit') }}</flux:button>
	</flux:modal.trigger>
	
	<flux:modal name="{{ $modalName }}" class="min-w-96 md:min-w-2xl">
		<livewire:clearance::permissions.permission-form
				:guard="$guard" :groupKey="$groupKey" :modalName="$modalName"
				:editingPrefix="$prefix" :key="$modalName"/>
	</flux:modal>
</x-clearance::dark-scope>
