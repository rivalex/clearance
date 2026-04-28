<div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-6">
    <h2 class="text-base font-semibold mb-4">
        {{ $editingPrefix !== '' ? 'Edit group: '.$editingPrefix : 'New permission group' }}
    </h2>

    @if($errorMessage)
        <div class="mb-4 rounded-md bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 px-4 py-3 text-sm text-red-700 dark:text-red-400">
            {{ $errorMessage }}
        </div>
    @endif

    {{-- Prefix + Guard --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 mb-5">
        <div>
            <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-1">
                Prefix
                <span class="font-normal text-zinc-400">(e.g. orders)</span>
            </label>
            <input wire:model="prefix"
                   type="text"
                   placeholder="e.g. orders"
                   @if($editingPrefix !== '') readonly @endif
                   class="w-full rounded-md border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-900
                          px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-zinc-400
                          @if($editingPrefix !== '') opacity-60 cursor-not-allowed @endif" />
        </div>

        @if($editingPrefix === '')
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

    {{-- Standard CRUD checkboxes --}}
    <div class="mb-5">
        <p class="text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-2">Standard abilities</p>
        <div class="flex flex-wrap gap-4">
            @foreach($standardAbilities as $ability)
                <label class="flex items-center gap-2 text-sm cursor-pointer select-none">
                    <input type="checkbox"
                           wire:model="crudAbilities"
                           value="{{ $ability }}"
                           class="rounded border-zinc-300 dark:border-zinc-600 text-zinc-700 focus:ring-zinc-400" />
                    <span class="font-mono">{{ $ability }}</span>
                </label>
            @endforeach
        </div>
    </div>

    {{-- Custom abilities pill list --}}
    <div class="mb-5">
        <p class="text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-2">Custom abilities</p>

        @if(count($customAbilities) > 0)
            <div class="flex flex-wrap gap-2 mb-3">
                @foreach($customAbilities as $index => $ability)
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium
                                 bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300">
                        <span class="font-mono">{{ $ability }}</span>
                        <button wire:click="removeCustomAbility({{ $index }})"
                                type="button"
                                class="ml-0.5 leading-none text-amber-600 dark:text-amber-400 hover:text-amber-900 dark:hover:text-amber-200 transition">
                            ×
                        </button>
                    </span>
                @endforeach
            </div>
        @endif

        <div class="flex items-center gap-2">
            <input wire:model="newCustomAbility"
                   wire:keydown.enter.prevent="addCustomAbility"
                   type="text"
                   placeholder="e.g. export"
                   class="w-48 rounded-md border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-900
                          px-3 py-1.5 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-zinc-400" />
            <button wire:click="addCustomAbility"
                    type="button"
                    class="px-3 py-1.5 text-xs font-medium bg-zinc-100 dark:bg-zinc-700 text-zinc-700 dark:text-zinc-200
                           rounded-md hover:bg-zinc-200 dark:hover:bg-zinc-600 transition">
                Add
            </button>
        </div>
    </div>

    {{-- Actions --}}
    <div class="flex items-center gap-3 pt-1">
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
