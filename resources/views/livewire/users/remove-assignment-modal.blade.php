<div>
    <flux:modal name="{{ $modalName }}" class="w-full md:w-[28rem]">
        <div class="space-y-4">
            <div class="pb-4 border-b border-zinc-200 dark:border-zinc-700">
                <flux:heading size="lg">{{ __('clearance::ui.user_clearance.remove.title') }}</flux:heading>

                <flux:text class="text-zinc-500 dark:text-zinc-400">
                    {!! __('clearance::ui.user_clearance.remove.confirm_text', [
                        'role' => e($role->name),
                    ]) !!}
                </flux:text>

                <flux:text class="text-xs font-medium text-zinc-700 dark:text-zinc-300">
                    {{ __('clearance::ui.user_clearance.remove.cascade_warning') }}
                </flux:text>
            </div>

            <flux:input wire:model="confirmText"
                        placeholder="DELETE {{ $role->name }}"
                        class="font-mono" />

            @if($errorMessage)
                <div role="alert" class="rounded-md bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 px-4 py-3 text-sm text-red-700 dark:text-red-400">
                    {{ $errorMessage }}
                </div>
            @endif

            <div class="flex items-center justify-between pt-4 mt-2 border-t border-zinc-200 dark:border-zinc-700">
                <flux:button x-on:click="$flux.modal({{ \Illuminate\Support\Js::from($modalName) }}).close()" variant="ghost">
                    {{ __('clearance::ui.common.cancel') }}
                </flux:button>
                <flux:button wire:click="confirmDelete" variant="danger">
                    {{ __('clearance::ui.user_clearance.remove.confirm_button') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
