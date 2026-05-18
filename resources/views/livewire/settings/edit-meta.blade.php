<div class="clearance">
    <flux:modal.trigger name="{{ $modalName }}">
        <flux:button size="xs" variant="ghost">{{ __('clearance::ui.settings.meta.edit') }}</flux:button>
    </flux:modal.trigger>

    <flux:modal name="{{ $modalName }}" class="w-full max-w-lg">
        <div class="space-y-5 p-1">
            <div>
                <flux:heading size="lg">
                    {{ __('clearance::ui.settings.meta.modal_title') }}
                    <span class="font-mono text-sm font-normal text-zinc-500 ml-1">{{ $subjectKey }}</span>
                </flux:heading>
                <flux:text class="text-zinc-500 dark:text-zinc-400">
                    {{ __('clearance::ui.settings.meta.modal_desc') }}
                </flux:text>
            </div>

            @if($errorMessage)
                <p class="text-sm text-red-600 dark:text-red-400">{{ $errorMessage }}</p>
            @endif

            <flux:input
                wire:model="displayName"
                label="{{ __('clearance::ui.settings.meta.display_name') }}"
                placeholder="{{ __('clearance::ui.settings.meta.display_name_placeholder') }}"
            />

            <flux:textarea
                wire:model="description"
                label="{{ __('clearance::ui.settings.meta.description') }}"
                placeholder="{{ __('clearance::ui.settings.meta.description_placeholder') }}"
                rows="2"
            />

            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">
                    {{ __('clearance::ui.settings.meta.color') }}
                </label>
                <div class="flex items-center gap-3">
                    <input type="color" wire:model="color"
                           class="h-9 w-14 cursor-pointer rounded border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 p-0.5" />
                    <flux:input wire:model="color" placeholder="#3b82f6" class="font-mono w-32" />
                    <button type="button" wire:click="$set('color', '')"
                            class="text-xs text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">
                        {{ __('clearance::ui.settings.meta.clear_color') }}
                    </button>
                </div>
                @error('color')
                    <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">
                    {{ __('clearance::ui.settings.meta.icon_svg') }}
                </label>
                @if($iconSvg)
                    <div class="mb-2 flex items-center gap-3">
                        <span class="inline-flex items-center justify-center w-8 h-8 rounded border border-zinc-200 dark:border-zinc-600 bg-zinc-50 dark:bg-zinc-900"
                              style="{{ $color ? 'color:'.$color : '' }}">{!! $iconSvg !!}</span>
                        <span class="text-xs text-zinc-400">{{ __('clearance::ui.settings.meta.icon_preview') }}</span>
                        <button type="button" wire:click="$set('iconSvg', '')"
                                class="text-xs text-red-400 hover:text-red-600">
                            {{ __('clearance::ui.settings.meta.clear_icon') }}
                        </button>
                    </div>
                @endif
                <flux:textarea wire:model="iconSvg"
                               placeholder="{{ __('clearance::ui.settings.meta.icon_svg_placeholder') }}"
                               rows="4" class="font-mono text-xs" />
                <p class="mt-1 text-xs text-zinc-400">{{ __('clearance::ui.settings.meta.icon_svg_hint') }}</p>
            </div>

            <div class="flex items-center justify-between pt-2">
                <flux:button x-on:click="$flux.modal('{{ $modalName }}').close()" variant="ghost">{{ __('clearance::ui.common.cancel') }}</flux:button>
                <flux:button wire:click="save" variant="primary">{{ __('clearance::ui.common.save') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
