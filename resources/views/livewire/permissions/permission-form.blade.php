<div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-6">
    <h2 class="text-base font-semibold mb-4">
        {{ $permissionId ? 'Edit permission' : 'New permission' }}
    </h2>

    @if($errorMessage)
        <div class="mb-4 rounded-md bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 px-4 py-3 text-sm text-red-700 dark:text-red-400">
            {{ $errorMessage }}
        </div>
    @endif

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div>
            <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-1">
                Permission name
                <span class="font-normal text-zinc-400">(gruppo-azione)</span>
            </label>
            <input wire:model="name"
                   type="text"
                   placeholder="e.g. orders-create"
                   class="w-full rounded-md border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-900
                          px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-zinc-400" />
        </div>

        @if($permissionId === null)
            <div>
                <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-1">Guard</label>
                <select wire:model="guardName"
                        class="w-full rounded-md border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-900
                               px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-zinc-400">
                    @foreach($availableGuards as $guard)
                        <option value="{{ $guard }}">{{ $guard }}</option>
                    @endforeach
                </select>
            </div>
        @endif
    </div>

    <div class="mt-4 flex items-center gap-3">
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
