<div class="clearance">
    <flux:modal.trigger name="{{ $modalName }}">
        <flux:button variant="ghost" size="xs" color="red" icon="trash">{{ __('clearance::ui.common.delete') }}</flux:button>
    </flux:modal.trigger>

    <flux:modal name="{{ $modalName }}" class="max-w-sm min-w-96 md:min-w-xl md:max-w-xl">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('clearance::ui.guards.delete.title') }}</flux:heading>

            <flux:text class="text-zinc-500 dark:text-zinc-400">
                {!! __('clearance::ui.guards.delete.desc', ['name' => $name, 'confirm' => '<b>DELETE ' . $name . '</b>']) !!}
            </flux:text>

            <flux:input wire:model="confirmText" placeholder="DELETE {{ $name }}" class="font-mono" />

            <div class="flex items-center justify-between pt-1">
                <flux:button x-on:click="$flux.modal('{{ $modalName }}').close()" variant="ghost">{{ __('clearance::ui.common.cancel') }}</flux:button>
                <flux:button wire:click="confirmDelete" variant="danger">{{ __('clearance::ui.guards.delete.confirm_delete') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
