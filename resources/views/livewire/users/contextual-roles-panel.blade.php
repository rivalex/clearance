<div class="clearance">
    {{-- Panel header --}}
    @php
        $panelLabel      = $contextModelConfig['label'] ?? class_basename($contextClass ?? '');
        $ctxMd5          = md5($contextClass ?? '');
        $assignModalName = 'assign-role-ctx-' . $ctxMd5 . '-' . $user->getKey();
    @endphp

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="text-base font-semibold">
                {{ __('clearance::ui.user_clearance.tabs.contextual') }}
                <span class="ml-1 text-sm font-normal text-zinc-400">({{ $panelLabel }})</span>
            </h2>
        </div>

        <flux:modal.trigger name="{{ $assignModalName }}">
            <flux:button variant="primary" size="sm" icon="plus">
                {{ __('clearance::ui.user_clearance.assign.title') }}
            </flux:button>
        </flux:modal.trigger>
    </div>

    {{-- Contextual assignment rows --}}
    @if($contextAssignments->isEmpty())
        <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 px-4 py-10 text-center text-sm text-zinc-400">
            {{ __('clearance::ui.user_clearance.no_contextual_roles') }}
        </div>
    @else
        <div class="space-y-4">
            @foreach($contextAssignments as $assignment)
                @php
                    $roleId      = (int) $assignment->role_id;
                    $contextId   = $assignment->context_id;
                    $role        = $assignment->role;
                    $rolePermIds = $role ? $role->permissions->pluck('id')->map(fn($id) => (int)$id)->toArray() : [];
                    $labelAttr   = $contextModelConfig['label_attribute'] ?? 'name';
                    $ctxInstance = $contextInstances->firstWhere('id', $contextId);
                    $ctxLabel    = $ctxInstance ? ($ctxInstance->{$labelAttr} ?? '#'.$contextId) : '#'.$contextId;
                    $removeModalName = 'remove-role-ctx-' . md5($contextClass ?? '') . '-' . $roleId . '-' . $contextId . '-' . $user->getKey();
                @endphp

                <flux:card class="space-y-4">
                    {{-- Card header --}}
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-semibold text-sm">{{ $role?->name ?? '—' }}</span>
                            @if($role)
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-zinc-100 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300 font-mono">
                                    {{ $role->guard_name }}
                                </span>
                            @endif
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-sky-100 text-sky-700 dark:bg-sky-900/30 dark:text-sky-300">
                                {{ $panelLabel }}: {{ $ctxLabel }}
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
                    @if($role && $role->permissions->isNotEmpty())
                        <div>
                            <p class="mb-2 text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide">
                                {{ __('clearance::ui.user_clearance.extra_perms.role_perms_label') }}
                            </p>
                            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-2">
                                @foreach($role->permissions as $perm)
                                    <label class="flex items-center gap-2 opacity-60 select-none">
                                        <input type="checkbox" checked disabled
                                               class="rounded border-zinc-300 dark:border-zinc-600 text-sky-600">
                                        <span class="text-xs font-mono leading-tight">{{ $perm->name }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    {{-- Contextual extra permissions (override checkboxes) --}}
                    @if($role && $role->permissions->isNotEmpty())
                        <div>
                            <p class="mb-2 text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide">
                                {{ __('clearance::ui.user_clearance.extra_perms.extra_perms_label') }}
                            </p>
                            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-2">
                                @foreach($role->permissions as $perm)
                                    @php $permId = (int) $perm->id; @endphp
                                    <label class="flex items-center gap-2 cursor-pointer">
                                        <input type="checkbox"
                                               wire:model="contextualPermissions.{{ $contextId }}.{{ $roleId }}.{{ $permId }}"
                                               class="rounded border-zinc-300 dark:border-zinc-600 text-sky-600 cursor-pointer">
                                        <span class="text-xs font-mono leading-tight">{{ $perm->name }}</span>
                                    </label>
                                @endforeach
                            </div>
                            <div class="mt-3 flex justify-end">
                                <flux:button wire:click="saveContextualExtraPerms({{ $roleId }}, {{ $contextId }})" size="sm" variant="filled">
                                    {{ __('clearance::ui.user_clearance.save_perms') }}
                                </flux:button>
                            </div>
                        </div>
                    @endif
                </flux:card>

                {{-- Remove assignment modal per row --}}
                <livewire:clearance::users.remove-assignment-modal
                    :userId="$user->getKey()"
                    :roleId="$roleId"
                    scope="contextual"
                    :contextClass="$contextClass ?? ''"
                    :contextId="$contextId"
                    :key="'rm-ctx-' . md5($contextClass ?? '') . '-' . $roleId . '-' . $contextId . '-' . $user->getKey()" />
            @endforeach
        </div>
    @endif

    {{-- Assign Role Modal (contextual scope) --}}
    <livewire:clearance::users.assign-role-modal
        :userId="$user->getKey()"
        scope="contextual"
        :contextClass="$contextClass ?? ''"
        :key="'arm-ctx-' . $ctxMd5 . '-' . $user->getKey()" />
</div>
