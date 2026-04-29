<div class="space-y-5">
    <div>
        <flux:heading size="lg">
            {{ $roleId ? 'Edit role' : 'New role' }}
        </flux:heading>
        <flux:text class="text-zinc-500 dark:text-zinc-400">
            {{ $roleId ? 'Update role name, guard, and permissions.' : 'Define a new role and assign permissions.' }}
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
            label="Role name"
            placeholder="e.g. editor"
        />

        @if($roleId === null)
            <flux:select wire:model.live="guardName" label="Guard">
                @foreach($availableGuards as $guard)
                    <option value="{{ $guard }}">{{ $guard }}</option>
                @endforeach
            </flux:select>
        @endif
    </div>

    <div class="flex items-center gap-6">
        <flux:checkbox wire:model="isSystem" label="System role" />
        <flux:checkbox wire:model="isProtected" label="Protected" />
    </div>

    @if(count($permissionOptions) > 0)
        <div>
            <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400 mb-2">Permissions</p>
            <flux:separator class="mb-3" />
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-y-1.5 gap-x-4 max-h-52 overflow-y-auto">
                @foreach($permissionOptions as $index => $opt)
                    <flux:checkbox
                        wire:model="permissionOptions.{{ $index }}.selected"
                        label="{{ $opt['name'] }}"
                        class="font-mono"
                    />
                @endforeach
            </div>
        </div>
    @endif

    <div class="flex items-center justify-between pt-2">
        <flux:button wire:click="cancel" variant="ghost">Cancel</flux:button>
        <flux:button wire:click="save" variant="primary">Save</flux:button>
    </div>
</div>
