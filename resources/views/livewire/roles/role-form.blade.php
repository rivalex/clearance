<div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-6">
    <h2 class="text-base font-semibold mb-4">
        {{ $roleId ? 'Edit role' : 'New role' }}
    </h2>

    @if($errorMessage)
        <div class="mb-4 rounded-md bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 px-4 py-3 text-sm text-red-700 dark:text-red-400">
            {{ $errorMessage }}
        </div>
    @endif

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 mb-4">
        <div>
            <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-1">Role name</label>
            <input wire:model="name"
                   type="text"
                   placeholder="e.g. editor"
                   class="w-full rounded-md border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-900
                          px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-zinc-400" />
        </div>

        @if($roleId === null)
            <div>
                <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-1">Guard</label>
                <select wire:model.live="guardName"
                        class="w-full rounded-md border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-900
                               px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-zinc-400">
                    @foreach($availableGuards as $guard)
                        <option value="{{ $guard }}">{{ $guard }}</option>
                    @endforeach
                </select>
            </div>
        @endif
    </div>

    <div class="flex items-center gap-6 mb-4">
        <label class="flex items-center gap-2 text-sm cursor-pointer">
            <input wire:model="isSystem" type="checkbox"
                   class="rounded border-zinc-300 dark:border-zinc-600 text-violet-600 focus:ring-violet-500" />
            <span>System role</span>
        </label>
        <label class="flex items-center gap-2 text-sm cursor-pointer">
            <input wire:model="isProtected" type="checkbox"
                   class="rounded border-zinc-300 dark:border-zinc-600 text-amber-600 focus:ring-amber-500" />
            <span>Protected</span>
        </label>
    </div>

    @if(count($permissionOptions) > 0)
        <div class="mb-4">
            <p class="text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-2">Permissions</p>
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-y-1 gap-x-4 max-h-48 overflow-y-auto">
                @foreach($permissionOptions as $index => $opt)
                    <label class="flex items-center gap-2 text-xs cursor-pointer">
                        <input wire:model="permissionOptions.{{ $index }}.selected" type="checkbox"
                               class="rounded border-zinc-300 dark:border-zinc-600 text-zinc-700 focus:ring-zinc-400" />
                        <span class="font-mono">{{ $opt['name'] }}</span>
                    </label>
                @endforeach
            </div>
        </div>
    @endif

    <div class="flex items-center gap-3">
        <button wire:click="save"
                class="px-4 py-2 text-sm font-medium bg-zinc-900 dark:bg-white text-white dark:text-zinc-900
                       rounded-lg hover:opacity-90 transition">
            Save
        </button>
        <button wire:click="cancel"
                class="px-4 py-2 text-sm font-medium text-zinc-600 dark:text-zinc-400 hover:underline">
            Cancel
        </button>
    </div>
</div>
