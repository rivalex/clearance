<div class="clearance">
    {{-- Panel header --}}
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-base font-semibold">{{ __('clearance::ui.user_clearance.tabs.global') }}</h2>

        <flux:modal.trigger name="{{ 'assign-role-global-' . $user->getKey() }}">
            <flux:button variant="primary" size="sm" icon="plus">
                {{ __('clearance::ui.user_clearance.assign.title') }}
            </flux:button>
        </flux:modal.trigger>
    </div>

    {{-- Role cards --}}
    @if(count($roleCards) === 0)
        <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 px-4 py-10 text-center text-sm text-zinc-400">
            {{ __('clearance::ui.user_clearance.no_global_roles') }}
        </div>
    @else
        <div class="space-y-4">
            @foreach($roleCards as $card)
                @php
                    $roleId      = (int) $card['role']->id;
                    $rolePermIds = $card['role_permission_ids'];
                    $rolePerms   = $card['guard_permissions']->whereIn('id', $rolePermIds);
                    $extraPerms  = $card['guard_permissions']->whereNotIn('id', $rolePermIds);
                    $removeModalName = 'remove-role-global-' . $roleId . '-' . $user->getKey();
                @endphp

                <flux:card class="space-y-4">
                    {{-- Card header --}}
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-semibold text-sm">{{ $card['role']->name }}</span>
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-zinc-100 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300 font-mono">
                                {{ $card['role']->guard_name }}
                            </span>
                            <span class="text-xs text-zinc-400">
                                {{ count($rolePermIds) }} {{ __('clearance::ui.user_clearance.extra_perms.role_perms_label') }}
                            </span>
                        </div>

                        <flux:modal.trigger name="{{ $removeModalName }}">
                            <flux:button variant="danger" size="sm" icon="trash">
                                {{ __('clearance::ui.user_clearance.remove_role') }}
                            </flux:button>
                        </flux:modal.trigger>
                    </div>

                    {{-- Role permissions (read-only) --}}
                    @if($rolePerms->isNotEmpty())
                        <div>
                            <p class="mb-2 text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide">
                                {{ __('clearance::ui.user_clearance.extra_perms.role_perms_label') }}
                            </p>
                            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-2">
                                @foreach($rolePerms as $perm)
                                    <label class="flex items-center gap-2 opacity-60 select-none">
                                        <input type="checkbox" checked disabled
                                               class="rounded border-zinc-300 dark:border-zinc-600 text-sky-600">
                                        <span class="text-xs font-mono leading-tight">{{ $perm->name }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    {{-- Extra permissions (editable) --}}
                    @if($extraPerms->isNotEmpty())
                        <div>
                            <p class="mb-2 text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide">
                                {{ __('clearance::ui.user_clearance.extra_perms.extra_perms_label') }}
                            </p>
                            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-2">
                                @foreach($extraPerms as $perm)
                                    <label class="flex items-center gap-2 cursor-pointer">
                                        <input type="checkbox"
                                               wire:model="manualPermissions.{{ $roleId }}.{{ $perm->id }}"
                                               class="rounded border-zinc-300 dark:border-zinc-600 text-sky-600 cursor-pointer">
                                        <span class="text-xs font-mono leading-tight">{{ $perm->name }}</span>
                                    </label>
                                @endforeach
                            </div>
                            <div class="mt-3 flex justify-end">
                                <flux:button wire:click="saveExtraPerms({{ $roleId }})" size="sm" variant="filled">
                                    {{ __('clearance::ui.user_clearance.save_perms') }}
                                </flux:button>
                            </div>
                        </div>
                    @endif
                </flux:card>

                {{-- Remove role modal per card --}}
                <livewire:clearance::users.remove-assignment-modal
                    :userId="$user->getKey()"
                    :roleId="$roleId"
                    scope="global"
                    :key="'rm-global-' . $roleId . '-' . $user->getKey()" />
            @endforeach
        </div>
    @endif

    {{-- Assign Role Modal (global scope) --}}
    <livewire:clearance::users.assign-role-modal
        :userId="$user->getKey()"
        scope="global"
        :key="'arm-global-' . $user->getKey()" />
</div>
