<div class="space-y-5 text-start">
    <div>
        <flux:heading size="xl">
            {{ $roleId ? __('clearance::ui.roles.form.edit_title') : __('clearance::ui.roles.form.new_title') }}
        </flux:heading>
        <flux:text class="text-zinc-500 dark:text-zinc-400">
            {{ $roleId ? __('clearance::ui.roles.form.edit_desc') : __('clearance::ui.roles.form.new_desc') }}
        </flux:text>
    </div>

    @if($errorMessage)
        <div class="rounded-md bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 px-4 py-3 text-sm text-red-700 dark:text-red-400">
            {{ $errorMessage }}
        </div>
    @endif

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <flux:input
            wire:model="name"
            label="{{ __('clearance::ui.roles.form.role_name') }}"
            placeholder="{{ __('clearance::ui.roles.form.role_name_placeholder') }}"
            @if($isSuperAdmin) disabled @endif
        />

        @if($roleId === null)
            <flux:select wire:model.live="guardName" label="{{ __('clearance::ui.common.guard') }}">
                @foreach($availableGuards as $guard)
                    <option value="{{ $guard }}">{{ $guard }}</option>
                @endforeach
            </flux:select>
        @endif
    </div>

    <div class="flex items-center gap-6">
        <flux:checkbox wire:model="isLocked" label="{{ __('clearance::ui.roles.form.locked') }}" @if($isSuperAdmin) disabled @endif />
    </div>

    {{-- M8: Role scope (global / contextual) --}}
    <div class="space-y-2 w-full">
        <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
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
        <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
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

    @if(count($permissionGroups) > 0)
        <div class="space-y-4">
            <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400 mb-2">{{ __('clearance::ui.roles.form.permissions') }}</p>
            <flux:separator class="mb-3" />
            
            <div class="max-h-96 overflow-y-auto pr-2 space-y-6">
                @foreach($permissionGroups as $groupKey => $group)
                    <div wire:key="group-{{ $groupKey }}">
                        <h3 class="text-sm font-semibold mb-2 text-zinc-700 dark:text-zinc-300">{{ $group['group'] }}</h3>
                        <div class="flex flex-wrap gap-2">
                            @foreach($group['abilities'] as $index => $ability)
                                @if($ability['locked'] ?? false)
                                    {{-- Locked: always selected, non-interactive --}}
                                    <span
                                        wire:key="perm-{{ $ability['id'] }}"
                                        class="crud-pills flex items-center justify-center gap-1.5 py-1 px-3 rounded-lg border opacity-60 cursor-not-allowed select-none
                                            @if($ability['color'] === 'green') bg-green-600 border-green-600 text-white
                                            @elseif($ability['color'] === 'red') bg-red-500 border-red-500 text-white
                                            @else bg-amber-500 border-amber-500 text-white @endif"
                                        title="{{ __('clearance::ui.roles.form.locked_permission') }}"
                                    >
                                        <svg class="w-3 h-3 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 1a4.5 4.5 0 00-4.5 4.5V9H5a2 2 0 00-2 2v6a2 2 0 002 2h10a2 2 0 002-2v-6a2 2 0 00-2-2h-.5V5.5A4.5 4.5 0 0010 1zm3 8V5.5a3 3 0 10-6 0V9h6z" clip-rule="evenodd"/></svg>
                                        <p class="text-xs font-medium">{{ \Illuminate\Support\Str::headline($ability['ability']) }}</p>
                                    </span>
                                @elseif($ability['out_of_ceiling'] ?? false)
                                    {{-- Out of ceiling: greyed, non-interactive --}}
                                    <span
                                        wire:key="perm-{{ $ability['id'] }}"
                                        class="crud-pills flex items-center justify-center gap-1.5 py-1 px-3 rounded-lg border border-zinc-200 dark:border-zinc-700 opacity-40 cursor-not-allowed select-none text-zinc-500 dark:text-zinc-500"
                                        title="{{ __('clearance::ui.roles.form.out_of_ceiling') }}"
                                    >
                                        <p class="text-xs font-medium">{{ \Illuminate\Support\Str::headline($ability['ability']) }}</p>
                                    </span>
                                @else
                                    <label
                                        wire:key="perm-{{ $ability['id'] }}"
                                        class="crud-pills flex items-center justify-center gap-2 py-1 px-3 rounded-lg border border-zinc-200 dark:border-zinc-700 cursor-pointer transition-colors"
                                        :class="{
                                            'bg-green-600 border-green-600 text-white': $wire.permissionGroups['{{ $groupKey }}'].abilities[{{ $index }}].selected && $wire.permissionGroups['{{ $groupKey }}'].abilities[{{ $index }}].color === 'green',
                                            'bg-red-500 border-red-500 text-white': $wire.permissionGroups['{{ $groupKey }}'].abilities[{{ $index }}].selected && $wire.permissionGroups['{{ $groupKey }}'].abilities[{{ $index }}].color === 'red',
                                            'bg-amber-500 border-amber-500 text-white': $wire.permissionGroups['{{ $groupKey }}'].abilities[{{ $index }}].selected && $wire.permissionGroups['{{ $groupKey }}'].abilities[{{ $index }}].color === 'amber',
                                            'hover:bg-zinc-50 dark:hover:bg-zinc-700': !$wire.permissionGroups['{{ $groupKey }}'].abilities[{{ $index }}].selected
                                        }"
                                    >
                                        <input type="checkbox" wire:model="permissionGroups.{{ $groupKey }}.abilities.{{ $index }}.selected" class="hidden">
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

    <div class="flex items-center justify-between pt-2">
        <flux:modal.close>
            <flux:button variant="ghost">{{ __('clearance::ui.common.cancel') }}</flux:button>
        </flux:modal.close>
        <flux:button wire:click="save" variant="primary">{{ __('clearance::ui.common.save') }}</flux:button>
    </div>
</div>
