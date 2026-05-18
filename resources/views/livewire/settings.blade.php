<div class="clearance space-y-8">

    {{-- ================================================================
         SEZIONE 1 - Impostazioni generali
    ================================================================ --}}
    <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-6">
        <h2 class="text-base font-semibold mb-1">{{ __('clearance::ui.settings.general.title') }}</h2>
        <p class="text-sm text-zinc-500 dark:text-zinc-400 mb-5">{{ __('clearance::ui.settings.general.description') }}</p>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
            {{-- Default role --}}
            <div>
                <flux:select
                    wire:model="defaultRole"
                    label="{{ __('clearance::ui.settings.general.default_role') }}"
                >
                    <option value="">{{ __('clearance::ui.settings.general.no_default_role') }}</option>
                    @foreach($roles as $role)
                        <option value="{{ $role->name }}">{{ $role->name }} ({{ $role->guard_name }})</option>
                    @endforeach
                </flux:select>
                <p class="mt-1 text-xs text-zinc-400">{{ __('clearance::ui.settings.general.default_role_hint') }}</p>
            </div>

            {{-- Show icons toggle --}}
            <div class="flex flex-col justify-center">
                <flux:checkbox
                    wire:model="showIcons"
                    label="{{ __('clearance::ui.settings.general.show_icons') }}"
                />
                <p class="mt-1 text-xs text-zinc-400 ml-6">{{ __('clearance::ui.settings.general.show_icons_hint') }}</p>
            </div>
        </div>

        <div class="mt-5 flex items-center gap-4">
            <flux:button wire:click="saveGeneral" variant="primary" size="sm">
                {{ __('clearance::ui.settings.general.save') }}
            </flux:button>

            @if($saveMessage)
                <span class="text-sm text-green-600 dark:text-green-400">{{ $saveMessage }}</span>
            @endif
        </div>
    </div>

    {{-- ================================================================
         SEZIONE 2 - Bulk assign ruolo di default
    ================================================================ --}}
    <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-6">
        <h2 class="text-base font-semibold mb-1">{{ __('clearance::ui.settings.bulk.title') }}</h2>
        <p class="text-sm text-zinc-500 dark:text-zinc-400 mb-5">{{ __('clearance::ui.settings.bulk.description') }}</p>

        <div class="flex items-center gap-4">
            <flux:button wire:click="bulkAssignDefaultRole" variant="filled" size="sm" wire:confirm="{{ __('clearance::ui.settings.bulk.confirm') }}">
                {{ __('clearance::ui.settings.bulk.action') }}
            </flux:button>

            @if($bulkMessage)
                <span class="text-sm {{ $bulkSuccess ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                    {{ $bulkMessage }}
                </span>
            @endif
        </div>
    </div>

    {{-- ================================================================
         SEZIONE 3 - Meta ruoli (icona, colore, display_name, description)
    ================================================================ --}}
    <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-6">
        <h2 class="text-base font-semibold mb-1">{{ __('clearance::ui.settings.roles_meta.title') }}</h2>
        <p class="text-sm text-zinc-500 dark:text-zinc-400 mb-4">{{ __('clearance::ui.settings.roles_meta.description') }}</p>

        <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
            <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700 text-sm">
                <thead class="bg-zinc-50 dark:bg-zinc-900">
                    <tr>
                        <th class="px-4 py-3 text-left font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide text-xs">{{ __('clearance::ui.common.role') }}</th>
                        <th class="px-4 py-3 text-left font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide text-xs">{{ __('clearance::ui.settings.meta.display_name') }}</th>
                        <th class="px-4 py-3 text-left font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide text-xs">{{ __('clearance::ui.settings.meta.icon') }}</th>
                        <th class="px-4 py-3 text-left font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide text-xs">{{ __('clearance::ui.settings.meta.color') }}</th>
                        <th class="px-4 py-3 text-right font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide text-xs">{{ __('clearance::ui.common.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                    @forelse($roles as $role)
                        @php $meta = $roleMetas->get($role->name); @endphp
                        <tr>
                            <td class="px-4 py-3 font-mono text-xs font-medium">
                                {{ $role->name }}
                                <span class="ml-1 text-zinc-400">({{ $role->guard_name }})</span>
                            </td>
                            <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300">
                                {{ $meta?->display_name ?? '-' }}
                            </td>
                            <td class="px-4 py-3">
                                @if($meta?->icon_svg)
                                    <span class="inline-flex items-center justify-center w-6 h-6" style="{{ $meta->color ? 'color:'.\Rivalex\Clearance\Support\SvgSanitizer::safeCssColor($meta->color) : '' }}">{!! $meta->icon_svg !!}</span>
                                @else
                                    <span class="text-zinc-300 dark:text-zinc-600">-</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                @if($meta?->color)
                                    <span class="inline-flex items-center gap-2">
                                        <span class="inline-block w-4 h-4 rounded-full border border-zinc-200 dark:border-zinc-600" style="background:{{ \Rivalex\Clearance\Support\SvgSanitizer::safeCssColor($meta->color) }}"></span>
                                        <span class="font-mono text-xs text-zinc-500">{{ $meta->color }}</span>
                                    </span>
                                @else
                                    <span class="text-zinc-300 dark:text-zinc-600">-</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                <flux:button size="xs" wire:click="openMetaModal('role', '{{ $role->name }}')">
                                    {{ __('clearance::ui.settings.meta.edit') }}
                                </flux:button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-6 text-center text-zinc-400">{{ __('clearance::ui.settings.roles_meta.empty') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- ================================================================
         SEZIONE 4 - Meta guardie
    ================================================================ --}}
    <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-6">
        <h2 class="text-base font-semibold mb-1">{{ __('clearance::ui.settings.guards_meta.title') }}</h2>
        <p class="text-sm text-zinc-500 dark:text-zinc-400 mb-4">{{ __('clearance::ui.settings.guards_meta.description') }}</p>

        <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
            <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700 text-sm">
                <thead class="bg-zinc-50 dark:bg-zinc-900">
                    <tr>
                        <th class="px-4 py-3 text-left font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide text-xs">{{ __('clearance::ui.common.guard') }}</th>
                        <th class="px-4 py-3 text-left font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide text-xs">{{ __('clearance::ui.settings.meta.display_name') }}</th>
                        <th class="px-4 py-3 text-left font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide text-xs">{{ __('clearance::ui.settings.meta.icon') }}</th>
                        <th class="px-4 py-3 text-left font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide text-xs">{{ __('clearance::ui.settings.meta.color') }}</th>
                        <th class="px-4 py-3 text-right font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide text-xs">{{ __('clearance::ui.common.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                    @forelse($guards as $guardName => $guardConfig)
                        @php $meta = $guardMetas->get($guardName); @endphp
                        <tr>
                            <td class="px-4 py-3 font-mono text-xs font-medium">{{ $guardName }}</td>
                            <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300">
                                {{ $meta?->display_name ?? '-' }}
                            </td>
                            <td class="px-4 py-3">
                                @if($meta?->icon_svg)
                                    <span class="inline-flex items-center justify-center w-6 h-6" style="{{ $meta->color ? 'color:'.\Rivalex\Clearance\Support\SvgSanitizer::safeCssColor($meta->color) : '' }}">{!! $meta->icon_svg !!}</span>
                                @else
                                    <span class="text-zinc-300 dark:text-zinc-600">-</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                @if($meta?->color)
                                    <span class="inline-flex items-center gap-2">
                                        <span class="inline-block w-4 h-4 rounded-full border border-zinc-200 dark:border-zinc-600" style="background:{{ \Rivalex\Clearance\Support\SvgSanitizer::safeCssColor($meta->color) }}"></span>
                                        <span class="font-mono text-xs text-zinc-500">{{ $meta->color }}</span>
                                    </span>
                                @else
                                    <span class="text-zinc-300 dark:text-zinc-600">-</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                <flux:button size="xs" wire:click="openMetaModal('guard', '{{ $guardName }}')">
                                    {{ __('clearance::ui.settings.meta.edit') }}
                                </flux:button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-6 text-center text-zinc-400">{{ __('clearance::ui.settings.guards_meta.empty') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- ================================================================
         MODAL - Modifica meta (icona SVG, colore, display_name, description)
    ================================================================ --}}
    <flux:modal wire:model="metaModalOpen" class="w-full max-w-lg">
        <div class="space-y-5 p-1">
            <div>
                <flux:heading size="lg">
                    {{ __('clearance::ui.settings.meta.modal_title') }}
                    <span class="font-mono text-sm font-normal text-zinc-500 ml-1">{{ $metaSubjectKey }}</span>
                </flux:heading>
                <flux:text class="text-zinc-500 dark:text-zinc-400">
                    {{ __('clearance::ui.settings.meta.modal_desc') }}
                </flux:text>
            </div>

            <flux:input
                wire:model="metaDisplayName"
                label="{{ __('clearance::ui.settings.meta.display_name') }}"
                placeholder="{{ __('clearance::ui.settings.meta.display_name_placeholder') }}"
            />

            <flux:textarea
                wire:model="metaDescription"
                label="{{ __('clearance::ui.settings.meta.description') }}"
                placeholder="{{ __('clearance::ui.settings.meta.description_placeholder') }}"
                rows="2"
            />

            {{-- Color hex picker --}}
            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">
                    {{ __('clearance::ui.settings.meta.color') }}
                </label>
                <div class="flex items-center gap-3">
                    <input
                        type="color"
                        wire:model="metaColor"
                        class="h-9 w-14 cursor-pointer rounded border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 p-0.5"
                    />
                    <flux:input
                        wire:model="metaColor"
                        placeholder="#3b82f6"
                        class="font-mono w-32"
                    />
                    <button
                        type="button"
                        wire:click="$set('metaColor', '')"
                        class="text-xs text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200"
                    >{{ __('clearance::ui.settings.meta.clear_color') }}</button>
                </div>
                @error('metaColor')
                    <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                @enderror
            </div>

            {{-- SVG icon textarea --}}
            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">
                    {{ __('clearance::ui.settings.meta.icon_svg') }}
                </label>
                @if($metaIconSvg)
                    <div class="mb-2 flex items-center gap-3">
                        <span class="inline-flex items-center justify-center w-8 h-8 rounded border border-zinc-200 dark:border-zinc-600 bg-zinc-50 dark:bg-zinc-900" style="{{ $metaColor ? 'color:'.$metaColor : '' }}">{!! $metaIconSvgPreview !!}</span>
                        <span class="text-xs text-zinc-400">{{ __('clearance::ui.settings.meta.icon_preview') }}</span>
                        <button
                            type="button"
                            wire:click="$set('metaIconSvg', '')"
                            class="text-xs text-red-400 hover:text-red-600"
                        >{{ __('clearance::ui.settings.meta.clear_icon') }}</button>
                    </div>
                @endif
                <flux:textarea
                    wire:model="metaIconSvg"
                    placeholder="{{ __('clearance::ui.settings.meta.icon_svg_placeholder') }}"
                    rows="4"
                    class="font-mono text-xs"
                />
                <p class="mt-1 text-xs text-zinc-400">{{ __('clearance::ui.settings.meta.icon_svg_hint') }}</p>
            </div>

            <div class="flex items-center justify-between pt-2">
                <flux:button wire:click="closeMetaModal" variant="ghost">{{ __('clearance::ui.common.cancel') }}</flux:button>
                <flux:button wire:click="saveMeta" variant="primary">{{ __('clearance::ui.common.save') }}</flux:button>
            </div>
        </div>
    </flux:modal>

</div>

@assets
@once
    <link rel="stylesheet" href="{{ route('clearance.assets', 'css/clearance.min.css') }}">
@endonce
@endassets
