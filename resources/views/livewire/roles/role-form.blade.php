<div class="space-y-5 text-start">
    <div class="pb-4 border-b border-gray-200 dark:border-gray-700">
        <flux:heading size="xl">
            {{ $roleId ? __('clearance::ui.roles.form.edit_title') : __('clearance::ui.roles.form.new_title') }}
        </flux:heading>
        <flux:text class="text-gray-500 dark:text-gray-400">
            {{ $roleId ? __('clearance::ui.roles.form.edit_desc') : __('clearance::ui.roles.form.new_desc') }}
        </flux:text>
    </div>

    @if($errorMessage)
        <div class="rounded-md bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 px-4 py-3 text-sm text-red-700 dark:text-red-400">
            {{ $errorMessage }}
        </div>
    @endif

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3 sm:items-end">
        <flux:input
            wire:model="name"
            label="{{ __('clearance::ui.roles.form.role_name') }}"
            placeholder="{{ __('clearance::ui.roles.form.role_name_placeholder') }}"
            :disabled="$isSuperAdmin"
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

    {{-- M8: Role scope (global / contextual) + parent role — advanced state, collapsed unless already in use --}}
    <details class="group/advanced space-y-4" @if($scope === 'contextual' || $parentRoleId) open @endif>
        <summary class="cursor-pointer text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 select-none list-none flex items-center gap-1.5">
            <svg class="w-3 h-3 shrink-0 transition-transform group-open/advanced:rotate-90" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
            {{ __('clearance::ui.roles.form.advanced') }}
        </summary>

        <div class="space-y-2 w-full mt-4">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                {{ __('clearance::ui.roles.form.scope') }}
            </p>
            <div class="flex items-center gap-6 w-full scope">
                <flux:radio.group wire:model.change.live="scope" class="max-sm:flex-col" variant="cards">
                    <flux:radio value="global" label="{{ __('clearance::ui.roles.form.scope_global') }}" description="{{ __('clearance::ui.roles.form.scope_global_desc') }}" />
                    <flux:radio value="contextual" label="{{ __('clearance::ui.roles.form.scope_contextual') }}" description="{{ __('clearance::ui.roles.form.scope_contextual_desc') }}" />
                </flux:radio.group>
            </div>
        </div>

        @if($scope === 'contextual' && count($availableContextTypes) > 0)
        <div class="space-y-2">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
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
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-2">{{ __('clearance::ui.roles.form.permissions') }}</p>
            <flux:separator class="mb-3" />
            
            <div class="max-h-96 overflow-y-auto pr-2 space-y-6">
                @foreach($permissionGroups as $groupKey => $group)
                    <div wire:key="group-{{ $groupKey }}">
                        <h3 class="text-sm font-semibold mb-2 text-gray-700 dark:text-gray-300">{{ $group['group'] }}</h3>
                        <div class="flex flex-wrap gap-2">
                            @foreach($group['abilities'] as $index => $ability)
                                @if($ability['locked'] ?? false)
                                    {{-- Locked: always selected, non-interactive. Ink fill — granted state is never conveyed by category hue. --}}
                                    <span
                                        wire:key="perm-{{ $ability['id'] }}"
                                        class="crud-pills flex items-center justify-center gap-1.5 py-1 px-3 rounded-lg border border-black-500 bg-black-500 text-white opacity-60 cursor-not-allowed select-none"
                                        title="{{ __('clearance::ui.roles.form.locked_permission') }}"
                                    >
                                        <svg class="w-3 h-3 shrink-0" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M10 1a4.5 4.5 0 00-4.5 4.5V9H5a2 2 0 00-2 2v6a2 2 0 002 2h10a2 2 0 002-2v-6a2 2 0 00-2-2h-.5V5.5A4.5 4.5 0 0010 1zm3 8V5.5a3 3 0 10-6 0V9h6z" clip-rule="evenodd"/></svg>
                                        <p class="text-xs font-medium">{{ \Illuminate\Support\Str::headline($ability['ability']) }}</p>
                                    </span>
                                @elseif($ability['out_of_ceiling'] ?? false)
                                    {{-- Out of ceiling: greyed, non-interactive --}}
                                    <span
                                        wire:key="perm-{{ $ability['id'] }}"
                                        class="crud-pills flex items-center justify-center gap-1.5 py-1 px-3 rounded-lg border border-gray-200 dark:border-gray-700 opacity-40 cursor-not-allowed select-none text-gray-500 dark:text-gray-500"
                                        title="{{ __('clearance::ui.roles.form.out_of_ceiling') }}"
                                    >
                                        <p class="text-xs font-medium">{{ \Illuminate\Support\Str::headline($ability['ability']) }}</p>
                                    </span>
                                @else
                                    <label
                                        wire:key="perm-{{ $ability['id'] }}"
                                        class="crud-pills {{ $ability['color'] }} flex items-center justify-center gap-1.5 py-1 px-3 rounded-lg border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300 cursor-pointer transition-colors has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-black-500 has-[:focus-visible]:ring-offset-2 dark:has-[:focus-visible]:ring-offset-gray-900"
                                        :data-checked="$wire.permissionGroups['{{ $groupKey }}'].abilities[{{ $index }}].selected"
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

    <div class="flex items-center justify-between pt-4 mt-2 border-t border-gray-200 dark:border-gray-700">
        <flux:modal.close>
            <flux:button variant="ghost">{{ __('clearance::ui.common.cancel') }}</flux:button>
        </flux:modal.close>
        <flux:button wire:click="save" variant="primary">{{ __('clearance::ui.common.save') }}</flux:button>
    </div>
</div>
