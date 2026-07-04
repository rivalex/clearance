<div class="clearance">
    <flux:modal name="{{ $modalName }}" class="max-w-sm min-w-96 md:min-w-xl md:max-w-xl">
        <div class="space-y-4">
            <div class="pb-4 border-b border-gray-200 dark:border-gray-700">
                <flux:heading size="lg">{{ __('clearance::ui.user_clearance.remove.title') }}</flux:heading>

                <flux:text class="text-gray-500 dark:text-gray-400">
                    {!! __('clearance::ui.user_clearance.remove.confirm_text', [
                        'role' => '<b>DELETE ' . e($role->name) . '</b>',
                    ]) !!}
                </flux:text>

                <flux:text class="text-xs font-medium text-gray-700 dark:text-gray-300">
                    {{ __('clearance::ui.user_clearance.remove.cascade_warning') }}
                </flux:text>
            </div>

            <flux:input wire:model="confirmText"
                        placeholder="DELETE {{ $role->name }}"
                        class="font-mono" />

            @if($errorMessage)
                <div class="rounded-md bg-red-50 dark:bg-red-900/20 px-4 py-3 text-sm text-red-700 dark:text-red-400">
                    {{ $errorMessage }}
                </div>
            @endif

            <div class="flex items-center justify-between pt-4 mt-2 border-t border-gray-200 dark:border-gray-700">
                <flux:button x-on:click="$flux.modal('{{ $modalName }}').close()" variant="ghost">
                    {{ __('clearance::ui.common.cancel') }}
                </flux:button>
                <flux:button wire:click="confirmDelete" variant="danger">
                    {{ __('clearance::ui.user_clearance.remove.confirm_button') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
