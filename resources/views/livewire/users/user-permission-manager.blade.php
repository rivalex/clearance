<div class="clearance">
    <x-clearance::branding />

    {{-- Header --}}
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-xl font-semibold">{{ __('clearance::ui.user_permissions.title') }}</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                {{ __('clearance::ui.user_permissions.subtitle', [
                    'user' => $user->name ?? $user->email ?? '#'.$user->getKey(),
                ]) }}
            </p>
        </div>

        @if(count($availableMasterRoles) > 0)
            <div class="flex items-center gap-2">
                <flux:select wire:model="selectedRoleId" size="sm" class="min-w-[180px]">
                    <option value="">{{ __('clearance::ui.user_permissions.select_role') }}</option>
                    @foreach($availableMasterRoles as $role)
                        <option value="{{ $role->id }}">{{ $role->name }}</option>
                    @endforeach
                </flux:select>
                <flux:button wire:click="assignRole" variant="primary" size="sm">
                    {{ __('clearance::ui.user_permissions.assign') }}
                </flux:button>
            </div>
        @endif
    </div>

    {{-- Role cards --}}
    @if(count($roleCards) === 0)
        <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 px-4 py-10 text-center text-sm text-zinc-400">
            {{ __('clearance::ui.user_permissions.no_roles') }}
        </div>
    @else
        <div class="space-y-4">
            @foreach($roleCards as $card)
                @php
                    $roleId      = (int) $card['role']->id;
                    $rolePermIds = $card['role_permission_ids'];
                    $rolePerms   = $card['guard_permissions']->whereIn('id', $rolePermIds);
                    $extraPerms  = $card['guard_permissions']->whereNotIn('id', $rolePermIds);
                @endphp

                <flux:card class="space-y-4">
                    {{-- Card header --}}
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-semibold text-sm">{{ $card['role']->name }}</span>
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-zinc-100 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300 font-mono">
                                {{ $card['role']->guard_name }}
                            </span>
                            @if(! $card['is_master'])
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">
                                    slave
                                </span>
                            @endif
                            <span class="text-xs text-zinc-400">
                                {{ count($rolePermIds) }} {{ __('clearance::ui.user_permissions.role_perms_count') }}
                            </span>
                        </div>
                        <flux:button wire:click="openRemoveModal({{ $roleId }})" variant="danger" size="sm">
                            {{ __('clearance::ui.user_permissions.remove_role') }}
                        </flux:button>
                    </div>

                    {{-- Permissions grid --}}
                    @if($card['guard_permissions']->isEmpty())
                        <p class="text-xs text-zinc-400">{{ __('clearance::ui.user_permissions.no_guard_perms') }}</p>
                    @else
                        {{-- Role permissions: checked + disabled --}}
                        @if($rolePerms->isNotEmpty())
                            <div>
                                <p class="mb-2 text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide">
                                    {{ __('clearance::ui.user_permissions.role_perms_label') }}
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

                        {{-- Extra permissions: editable checkboxes --}}
                        @if($extraPerms->isNotEmpty())
                            <div>
                                <p class="mb-2 text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide">
                                    {{ __('clearance::ui.user_permissions.extra_perms_label') }}
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
                                    <flux:button wire:click="savePermissions({{ $roleId }})" size="sm" variant="filled">
                                        {{ __('clearance::ui.user_permissions.save_perms') }}
                                    </flux:button>
                                </div>
                            </div>
                        @endif
                    @endif
                </flux:card>
            @endforeach
        </div>
    @endif

    {{-- Remove role confirmation modal --}}
    <flux:modal wire:model="showRemoveModal" class="md:w-[34rem]">
        <div class="clearance space-y-4">
            <div>
                <flux:heading size="lg">{{ __('clearance::ui.user_permissions.remove_title') }}</flux:heading>
                <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">
                    {{ __('clearance::ui.user_permissions.remove_warning') }}
                </flux:text>
            </div>
            <div class="flex items-center justify-between pt-2">
                <flux:button wire:click="closeRemoveModal" variant="ghost">
                    {{ __('clearance::ui.common.cancel') }}
                </flux:button>
                <flux:button wire:click="confirmRemoveRole" variant="danger">
                    {{ __('clearance::ui.user_permissions.remove_confirm') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>

@assets
@once
    <link rel="stylesheet" href="{{ route('clearance.assets', 'css/clearance.min.css') }}">
@endonce
@endassets
