<div>
    <flux:modal.trigger name="{{ $modalName }}">
        <flux:button size="xs" variant="ghost">{{ __('clearance::ui.settings.meta.edit') }}</flux:button>
    </flux:modal.trigger>

    <flux:modal name="{{ $modalName }}" class="w-full md:w-[32rem]">
        <div class="space-y-5 p-1">
            <div class="pb-4 border-b border-zinc-200 dark:border-zinc-700">
                <flux:heading size="lg">
                    {{ __('clearance::ui.settings.meta.modal_title') }}
                    <span class="font-mono text-sm font-normal text-zinc-500 dark:text-zinc-400 ms-1">{{ $subjectKey }}</span>
                </flux:heading>
                <flux:text class="text-zinc-500 dark:text-zinc-400">
                    {{ __('clearance::ui.settings.meta.modal_desc') }}
                </flux:text>
            </div>

            @if($errorMessage)
                <div role="alert" class="rounded-md bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 px-4 py-3 text-sm text-red-700 dark:text-red-400">
                    {{ $errorMessage }}
                </div>
            @endif

            <flux:input
                wire:model="displayName"
                label="{{ __('clearance::ui.settings.meta.display_name') }}"
                placeholder="{{ __('clearance::ui.settings.meta.display_name_placeholder') }}"
                autofocus
            />

            <flux:textarea
                wire:model="description"
                label="{{ __('clearance::ui.settings.meta.description') }}"
                placeholder="{{ __('clearance::ui.settings.meta.description_placeholder') }}"
                rows="2"
            />

            <flux:field>
                <flux:label>{{ __('clearance::ui.settings.meta.color') }}</flux:label>
                <div class="flex flex-wrap items-center gap-3">
                    <input type="color" wire:model="color"
                           class="h-9 w-14 cursor-pointer rounded border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 p-0.5" />
                    <flux:input wire:model="color" placeholder="#3b82f6" class="font-mono w-32" />
                    <flux:button type="button" wire:click="$set('color', '')" variant="ghost" size="xs">
                        {{ __('clearance::ui.settings.meta.clear_color') }}
                    </flux:button>
                </div>
                <flux:error name="color" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('clearance::ui.settings.meta.icon_svg') }}</flux:label>
                @if($iconSvg)
                    <div class="mb-2 flex items-center gap-3">
                        <span class="inline-flex items-center justify-center w-8 h-8 rounded border border-zinc-200 dark:border-zinc-600 bg-zinc-50 dark:bg-zinc-900"
                              style="color:{{ \Rivalex\Clearance\Support\SvgSanitizer::safeCssColor($color) }}">{!! $iconSvgPreview !!}</span>
                        <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('clearance::ui.settings.meta.icon_preview') }}</span>
                        <flux:button type="button" wire:click="$set('iconSvg', '')" variant="ghost" size="xs" color="red" icon="trash">
                            {{ __('clearance::ui.settings.meta.clear_icon') }}
                        </flux:button>
                    </div>
                @endif
                <flux:textarea wire:model="iconSvg"
                               placeholder="{{ __('clearance::ui.settings.meta.icon_svg_placeholder') }}"
                               rows="4" class="font-mono text-xs" />
                <flux:description>{{ __('clearance::ui.settings.meta.icon_svg_hint') }}</flux:description>
            </flux:field>

            <div class="flex items-center justify-between pt-4 mt-2 border-t border-zinc-200 dark:border-zinc-700">
                <flux:button x-on:click="$flux.modal({{ \Illuminate\Support\Js::from($modalName) }}).close()" variant="ghost">{{ __('clearance::ui.common.cancel') }}</flux:button>
                <flux:button wire:click="save" variant="primary">{{ __('clearance::ui.common.save') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
