<div class="clearance">
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold">Hierarchy</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Single-level parent→child role relationships and permission overrides.</p>
        </div>
        <flux:button wire:click="openAddRelation" variant="primary" size="sm" icon="plus">
            Add relation
        </flux:button>
    </div>

    @if($errorMessage)
        <div class="mb-4 rounded-md bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 px-4 py-3 text-sm text-red-700 dark:text-red-400">
            {{ $errorMessage }}
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
                        <button wire:click="openOverrideForm({{ $hierarchy->id }})"
                                class="text-xs text-zinc-500 dark:text-zinc-400 hover:underline">
                            + Override
                        </button>
                        <button wire:click="removeRelation({{ $hierarchy->id }})"
                                wire:confirm="Remove this hierarchy relation?"
                                class="text-xs text-red-600 dark:text-red-400 hover:underline">Remove</button>
                    </div>
                </div>

                @if($drilldownId === $hierarchy->id)
                    <div class="border-t border-zinc-100 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-900 px-4 py-3">
                        @if(count($hierarchy->overrides) > 0)
                            <div class="space-y-1">
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
                        @else
                            <p class="text-xs text-zinc-400">No overrides defined.</p>
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

    {{-- Add relation modal --}}
    <flux:modal wire:model="showAddRelation" class="md:w-[36rem]">
        <div class="clearance space-y-5">
            <div>
                <flux:heading size="lg">New parent → child relation</flux:heading>
                <flux:text class="text-zinc-500 dark:text-zinc-400">
                    Create a single-level inheritance between two roles.
                </flux:text>
            </div>

            @if($errorMessage)
                <div class="rounded-md bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 px-4 py-3 text-sm text-red-700 dark:text-red-400">
                    {{ $errorMessage }}
                </div>
            @endif

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <flux:select wire:model="newParentId" label="Parent role">
                    <option value="">— select —</option>
                    @foreach($allRoles as $role)
                        <option value="{{ $role->id }}">{{ $role->name }}</option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="newChildId" label="Child role">
                    <option value="">— select —</option>
                    @foreach($allRoles as $role)
                        <option value="{{ $role->id }}">{{ $role->name }}</option>
                    @endforeach
                </flux:select>
            </div>

            <div class="flex items-center justify-between pt-2">
                <flux:button wire:click="closeAddRelation" variant="ghost">Cancel</flux:button>
                <flux:button wire:click="addRelation" variant="primary">Create</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Add override modal --}}
    <flux:modal wire:model="showOverrideForm" class="md:w-[36rem]">
        <div class="clearance space-y-5">
            <div>
                <flux:heading size="lg">Add permission override</flux:heading>
                <flux:text class="text-zinc-500 dark:text-zinc-400">
                    Force a permission on or off for the child role in this hierarchy.
                </flux:text>
            </div>

            @if($errorMessage)
                <div class="rounded-md bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 px-4 py-3 text-sm text-red-700 dark:text-red-400">
                    {{ $errorMessage }}
                </div>
            @endif

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <flux:select wire:model="overridePermissionId" label="Permission">
                    <option value="">— select permission —</option>
                    @foreach($allPermissions as $permission)
                        <option value="{{ $permission->id }}">{{ $permission->name }}</option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="overrideType" label="Override type">
                    <option value="forced_on">forced_on</option>
                    <option value="forced_off">forced_off</option>
                </flux:select>
            </div>

            <div class="flex items-center justify-between pt-2">
                <flux:button wire:click="closeOverrideForm" variant="ghost">Cancel</flux:button>
                <flux:button wire:click="addOverride" variant="primary">Add override</flux:button>
            </div>
        </div>
    </flux:modal>
</div>

@assets
@once
    <link rel="stylesheet" href="{{ route('clearance.assets', 'css/clearance.min.css') }}">
@endonce
@endassets
