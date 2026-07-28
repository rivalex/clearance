<x-clearance::dark-scope>
    <flux:modal.trigger name="{{ $modalName }}">
        <flux:button variant="ghost" size="xs" color="red" icon="trash">{{ __('clearance::ui.common.delete') }}</flux:button>
    </flux:modal.trigger>

    <flux:modal name="{{ $modalName }}" class="max-w-sm min-w-96 md:min-w-xl md:max-w-xl">
        <div class="space-y-4">
            <div class="pb-4 border-b border-gray-200 dark:border-gray-700">
                <flux:heading size="xl">{{ __('clearance::ui.permissions.delete.title') }}</flux:heading>

                <flux:text class="text-gray-500 dark:text-gray-400">
                    {!! __('clearance::ui.permissions.delete.desc', ['group' => e($prefix), 'confirm' => '<b>DELETE ' . e($prefix) . '</b>']) !!}
                </flux:text>
            </div>

            <flux:input wire:model="confirmText" placeholder="DELETE {{ $prefix }}" class="font-mono" />

            <div class="flex items-center justify-between pt-4 mt-2 border-t border-gray-200 dark:border-gray-700">
                <flux:button x-on:click="$flux.modal('{{ $modalName }}').close()" variant="ghost">{{ __('clearance::ui.common.cancel') }}</flux:button>
                <flux:button wire:click="confirmDelete" variant="danger">{{ __('clearance::ui.permissions.delete.confirm_delete') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</x-clearance::dark-scope>
