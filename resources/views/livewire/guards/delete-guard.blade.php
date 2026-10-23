<x-clearance::dark-scope>
    <flux:modal.trigger name="{{ $modalName }}">
        <flux:button variant="ghost" size="xs" color="red" icon="trash">{{ __('clearance::ui.common.delete') }}</flux:button>
    </flux:modal.trigger>

    <flux:modal name="{{ $modalName }}" class="max-w-sm min-w-96 md:min-w-xl md:max-w-xl">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('clearance::ui.guards.delete.title') }}</flux:heading>

            <flux:text class="text-gray-500 dark:text-gray-400">
                {!! __('clearance::ui.guards.delete.desc', ['name' => e($name), 'confirm' => '<b>DELETE ' . e($name) . '</b>']) !!}
            </flux:text>

            @if($errorMessage)
                <div role="alert" class="rounded-md bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 px-4 py-3 text-sm text-red-700 dark:text-red-400">
                    {{ $errorMessage }}
                </div>
            @endif

            <flux:input wire:model="confirmText" placeholder="DELETE {{ $name }}" class="font-mono" />

            <div class="flex items-center justify-between pt-1">
                <flux:button x-on:click="$flux.modal({{ \Illuminate\Support\Js::from($modalName) }}).close()" variant="ghost">{{ __('clearance::ui.common.cancel') }}</flux:button>
                <flux:button wire:click="confirmDelete" variant="danger">{{ __('clearance::ui.guards.delete.confirm_delete') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</x-clearance::dark-scope>
