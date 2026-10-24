<div class="space-y-5 text-start">
    <div class="pb-4 border-b border-zinc-200 dark:border-zinc-700">
        <flux:heading size="xl">
            {{ $roleId ? __('clearance::ui.roles.form.edit_title') : __('clearance::ui.roles.form.new_title') }}
        </flux:heading>
        <flux:text class="text-zinc-500 dark:text-zinc-400">
            {{ $roleId ? __('clearance::ui.roles.form.edit_desc') : __('clearance::ui.roles.form.new_desc') }}
        </flux:text>
    </div>

    @if($errorMessage)
        <div role="alert" class="rounded-md bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 px-4 py-3 text-sm text-red-700 dark:text-red-400">
            {{ $errorMessage }}
        </div>
    @endif

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3 sm:items-end">
        <flux:input
            wire:model="name"
            label="{{ __('clearance::ui.roles.form.role_name') }}"
            placeholder="{{ __('clearance::ui.roles.form.role_name_placeholder') }}"
            :disabled="$isSuperAdmin"
            :autofocus="! $isSuperAdmin"
        />

        @if($roleId === null)
            <flux:select wire:model.live="guardName" label="{{ __('clearance::ui.common.guard') }}">
                @foreach($availableGuards as $guard)
                    <option value="{{ $guard }}">{{ $guard }}</option>
                @endforeach
            </flux:select>
        @endif

        <flux:field>
            <flux:label>{{ __('clearance::ui.roles.form.locked') }}</flux:label>
            <flux:switch wire:model="isLocked" :disabled="$isSuperAdmin" />
        </flux:field>
    </div>

    {{-- M8: Role scope (global / contextual) + parent role - advanced state, collapsed unless already in use --}}
    <details class="group/advanced space-y-4" @if($scope === 'contextual' || $parentRoleId) open @endif>
        <summary class="cursor-pointer text-sm font-medium text-zinc-700 dark:text-zinc-300 select-none list-none flex items-center gap-1.5">
            <svg class="w-3 h-3 shrink-0 transition-transform group-open/advanced:rotate-90" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
            {{ __('clearance::ui.roles.form.advanced') }}
        </summary>

        <div class="space-y-2 w-full mt-4">
            <p class="text-sm font-medium text-zinc-700 dark:text-zinc-300">
                {{ __('clearance::ui.roles.form.scope') }}
            </p>
            <div class="flex items-center gap-6 w-full">
                <flux:radio.group wire:model.change.live="scope" class="max-sm:flex-col w-full" variant="cards" :disabled="$isSuperAdmin">
                    <flux:radio value="global" label="{{ __('clearance::ui.roles.form.scope_global') }}" description="{{ __('clearance::ui.roles.form.scope_global_desc') }}" />
                    <flux:radio value="contextual" label="{{ __('clearance::ui.roles.form.scope_contextual') }}" description="{{ __('clearance::ui.roles.form.scope_contextual_desc') }}" />
                </flux:radio.group>
            </div>
        </div>

        @if($scope === 'contextual' && count($availableContextTypes) > 0)
        <div class="space-y-2">
            <p class="text-sm font-medium text-zinc-700 dark:text-zinc-300">
                {{ __('clearance::ui.roles.form.context_types') }}
            </p>
            <div class="flex flex-col gap-2">
                @foreach($availableContextTypes as $fqcn => $label)
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" wire:model="contextTypes" value="{{ $fqcn }}" class="rounded">
                        <span class="text-sm">{{ $label }}</span>
                    </label>
                @endforeach
            </div>
        </div>
        @endif

        @if(! $isSuperAdmin && count($availableParentRoles) > 0)
        <div class="space-y-2">
            <flux:select wire:model.live="parentRoleId" label="{{ __('clearance::ui.roles.form.parent_role') }}">
                <option value="">{{ __('clearance::ui.roles.form.parent_role_none') }}</option>
                @foreach($availableParentRoles as $id => $roleName)
                    <option value="{{ $id }}">{{ $roleName }}</option>
                @endforeach
            </flux:select>
        </div>
        @endif
    </details>

    @if(count($permissionGroups) > 0)
        <div class="space-y-4">
            <p class="text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-2">{{ __('clearance::ui.roles.form.permissions') }}</p>
            <flux:separator class="mb-3" />
            
            <div class="max-h-96 overflow-y-auto pe-2 space-y-6">
                @foreach($permissionGroups as $groupKey => $group)
                    <div wire:key="group-{{ $groupKey }}">
                        <h3 class="text-sm font-semibold mb-2 text-zinc-700 dark:text-zinc-300">{{ $group['group'] }}</h3>
                        <div class="flex flex-wrap gap-2">
                            @foreach($group['abilities'] as $index => $ability)
                                @if($ability['locked'] ?? false)
                                    {{-- Locked: always selected, non-interactive. Ink fill - granted state is never conveyed by category hue. --}}
                                    <span
                                        wire:key="perm-{{ $ability['id'] }}"
                                        class="font-light flex items-center justify-center gap-1.5 py-1 px-3 rounded-lg border border-accent bg-accent text-accent-foreground cursor-not-allowed select-none"
                                        title="{{ __('clearance::ui.roles.form.locked_permission') }}"
                                    >
                                        <span class="sr-only">{{ __('clearance::ui.roles.form.locked_permission') }}</span>
                                        <svg class="w-3 h-3 shrink-0" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M10 1a4.5 4.5 0 00-4.5 4.5V9H5a2 2 0 00-2 2v6a2 2 0 002 2h10a2 2 0 002-2v-6a2 2 0 00-2-2h-.5V5.5A4.5 4.5 0 0010 1zm3 8V5.5a3 3 0 10-6 0V9h6z" clip-rule="evenodd"/></svg>
                                        <p class="text-xs font-medium">{{ \Illuminate\Support\Str::headline($ability['ability']) }}</p>
                                    </span>
                                @elseif($ability['out_of_ceiling'] ?? false)
                                    {{-- Out of ceiling: greyed, non-interactive --}}
                                    <span
                                        wire:key="perm-{{ $ability['id'] }}"
                                        class="font-light flex items-center justify-center gap-1.5 py-1 px-3 rounded-lg border border-dashed border-zinc-300 dark:border-zinc-600 cursor-not-allowed select-none text-zinc-500 dark:text-zinc-400"
                                        title="{{ __('clearance::ui.roles.form.out_of_ceiling') }}"
                                    >
                                        <svg class="w-3 h-3 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M5.6 5.6l12.8 12.8"/></svg>
                                        <span class="sr-only">{{ __('clearance::ui.roles.form.out_of_ceiling') }}</span>
                                        <p class="text-xs font-medium">{{ \Illuminate\Support\Str::headline($ability['ability']) }}</p>
                                    </span>
                                @else
                                    @php
                                        $pillClasses = match ($ability['color']) {
                                            'green' => 'font-light select-none hover:text-green-700 hover:border-green-200 hover:bg-green-50 data-checked:bg-green-600 data-checked:text-white data-checked:border-green-600 data-checked:hover:bg-green-700 data-checked:hover:border-green-700',
                                            'red' => 'font-light select-none hover:text-red-700 hover:border-red-200 hover:bg-red-50 data-checked:bg-red-600 data-checked:text-white data-checked:border-red-600 data-checked:hover:bg-red-700 data-checked:hover:border-red-700',
                                            default => 'font-light select-none hover:text-amber-700 hover:border-amber-200 hover:bg-amber-50 data-checked:bg-amber-600 data-checked:text-white data-checked:border-amber-600 data-checked:hover:bg-amber-700 data-checked:hover:border-amber-700',
                                        };
                                    @endphp
                                    <label
                                        wire:key="perm-{{ $ability['id'] }}"
                                        class="{{ $pillClasses }} flex items-center justify-center gap-1.5 py-1 px-3 rounded-lg border border-zinc-200 dark:border-zinc-700 text-zinc-700 dark:text-zinc-300 cursor-pointer transition-colors has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-accent has-[:focus-visible]:ring-offset-2 dark:has-[:focus-visible]:ring-offset-zinc-900"
                                        :data-checked="$wire.permissionGroups[@js($groupKey)].abilities[{{ (int) $index }}].selected"
                                    >
                                        <input type="checkbox" wire:model="permissionGroups.{{ $groupKey }}.abilities.{{ $index }}.selected" class="sr-only">
                                        <p class="text-xs font-medium">{{ \Illuminate\Support\Str::headline($ability['ability']) }}</p>
                                    </label>
                                @endif
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <div class="flex items-center justify-between pt-4 mt-2 border-t border-zinc-200 dark:border-zinc-700">
        <flux:modal.close>
            <flux:button variant="ghost">{{ __('clearance::ui.common.cancel') }}</flux:button>
        </flux:modal.close>
        @unless($readOnly)
            <flux:button wire:click="save" variant="primary">{{ __('clearance::ui.common.save') }}</flux:button>
        @endunless
    </div>
</div>
