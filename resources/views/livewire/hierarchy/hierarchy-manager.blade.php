<div>
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold">Hierarchy</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Single-level parent→child role relationships and permission overrides.</p>
        </div>
        @unless($showAddRelation)
            <button wire:click="$set('showAddRelation', true)"
                    class="px-4 py-2 text-sm font-medium bg-zinc-900 dark:bg-white text-white dark:text-zinc-900 rounded-lg hover:opacity-90 transition">
                Add relation
            </button>
        @endunless
    </div>

    @if($errorMessage)
        <div class="mb-4 rounded-md bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 px-4 py-3 text-sm text-red-700 dark:text-red-400">
            {{ $errorMessage }}
        </div>
    @endif

    @if($showAddRelation)
        <div class="mb-6 rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-5">
            <h2 class="text-sm font-semibold mb-3">New parent → child relation</h2>
            <div class="grid grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-1">Parent role</label>
                    <select wire:model="newParentId"
                            class="w-full rounded-md border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-900 px-3 py-2 text-sm">
                        <option value="">— select —</option>
                        @foreach($allRoles as $role)
                            <option value="{{ $role->id }}">{{ $role->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-1">Child role</label>
                    <select wire:model="newChildId"
                            class="w-full rounded-md border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-900 px-3 py-2 text-sm">
                        <option value="">— select —</option>
                        @foreach($allRoles as $role)
                            <option value="{{ $role->id }}">{{ $role->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="flex gap-3">
                <button wire:click="addRelation"
                        class="px-4 py-2 text-sm font-medium bg-zinc-900 dark:bg-white text-white dark:text-zinc-900 rounded-lg hover:opacity-90 transition">
                    Create
                </button>
                <button wire:click="$set('showAddRelation', false)"
                        class="text-sm text-zinc-500 hover:underline">Cancel</button>
            </div>
        </div>
    @endif

    @if(count($orphanRoles) > 0)
        <div class="mb-4 flex flex-wrap gap-2 items-center">
            <span class="text-xs text-zinc-500">Orphan roles:</span>
            @foreach($orphanRoles as $orphan)
                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-zinc-100 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300">
                    {{ $orphan->name }}
                </span>
            @endforeach
        </div>
    @endif

    <div class="space-y-2">
        @forelse($hierarchies as $hierarchy)
            <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 overflow-hidden">
                <div class="flex items-center justify-between px-4 py-3">
                    <div class="flex items-center gap-2 text-sm">
                        <span class="font-medium">{{ $hierarchy->parentRole->name }}</span>
                        <span class="text-zinc-400">→</span>
                        <span class="font-medium">{{ $hierarchy->childRole->name }}</span>
                        @if(count($hierarchy->overrides) > 0)
                            <span class="ml-1 inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-sky-100 text-sky-700 dark:bg-sky-900/30 dark:text-sky-400">
                                {{ count($hierarchy->overrides) }} override(s)
                            </span>
                        @endif
                    </div>
                    <div class="flex items-center gap-3">
                        <button wire:click="drilldown({{ $hierarchy->id }})"
                                class="text-xs text-sky-600 dark:text-sky-400 hover:underline">
                            {{ $drilldownId === $hierarchy->id ? 'Hide' : 'Overrides' }}
                        </button>
                        <button wire:click="removeRelation({{ $hierarchy->id }})"
                                wire:confirm="Remove this hierarchy relation?"
                                class="text-xs text-red-600 dark:text-red-400 hover:underline">Remove</button>
                    </div>
                </div>

                @if($drilldownId === $hierarchy->id)
                    <div class="border-t border-zinc-100 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-900 px-4 py-3">
                        @if(count($hierarchy->overrides) > 0)
                            <div class="space-y-1 mb-3">
                                @foreach($hierarchy->overrides as $override)
                                    <div class="flex items-center justify-between text-xs">
                                        <div class="flex items-center gap-2">
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded font-medium
                                                         {{ $override->override_type === 'forced_on'
                                                            ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400'
                                                            : 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400' }}">
                                                {{ $override->override_type }}
                                            </span>
                                            <span class="font-mono">{{ $override->permission->name }}</span>
                                        </div>
                                        <button wire:click="removeOverride({{ $override->id }})"
                                                class="text-red-500 hover:underline">Remove</button>
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        @if($showOverrideForm && $overrideHierarchyId === $hierarchy->id)
                            <div class="flex items-center gap-3 flex-wrap mt-2">
                                <select wire:model="overridePermissionId"
                                        class="rounded-md border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 px-2 py-1.5 text-xs">
                                    <option value="">— permission —</option>
                                    @foreach($allPermissions as $permission)
                                        <option value="{{ $permission->id }}">{{ $permission->name }}</option>
                                    @endforeach
                                </select>
                                <select wire:model="overrideType"
                                        class="rounded-md border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 px-2 py-1.5 text-xs">
                                    <option value="forced_on">forced_on</option>
                                    <option value="forced_off">forced_off</option>
                                </select>
                                <button wire:click="addOverride"
                                        class="px-3 py-1.5 text-xs font-medium bg-zinc-900 dark:bg-white text-white dark:text-zinc-900 rounded hover:opacity-90 transition">
                                    Add
                                </button>
                                <button wire:click="$set('showOverrideForm', false)"
                                        class="text-xs text-zinc-500 hover:underline">Cancel</button>
                            </div>
                        @else
                            <button wire:click="openOverrideForm({{ $hierarchy->id }})"
                                    class="text-xs text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200 hover:underline">
                                + Add override
                            </button>
                        @endif
                    </div>
                @endif
            </div>
        @empty
            <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 px-4 py-8 text-center text-sm text-zinc-400">
                No hierarchy relations defined yet.
            </div>
        @endforelse
    </div>
</div>
