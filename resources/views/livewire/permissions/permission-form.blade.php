<div class="space-y-5">
    <div>
        <flux:heading size="lg">
            {{ $editingPrefix !== '' ? 'Edit group: '.$editingPrefix : 'New permission group' }}
        </flux:heading>
        <flux:text class="text-zinc-500 dark:text-zinc-400">
            {{ $editingPrefix !== '' ? 'Update abilities for this permission group.' : 'Define a prefix and select which abilities to create.' }}
        </flux:text>
    </div>

    @if($errorMessage)
        <div class="rounded-md bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 px-4 py-3 text-sm text-red-700 dark:text-red-400">
            {{ $errorMessage }}
        </div>
    @endif

    {{-- Prefix + Guard --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <flux:input
            wire:model="prefix"
            label="Prefix"
            placeholder="e.g. orders"
            class="font-mono"
            :readonly="$editingPrefix !== ''"
            :disabled="$editingPrefix !== ''"
        />

        @if($editingPrefix === '')
            <flux:select wire:model="guardName" label="Guard">
                @foreach($availableGuards as $guard)
                    <option value="{{ $guard }}">{{ $guard }}</option>
                @endforeach
            </flux:select>
        @endif
    </div>

    {{-- Standard CRUD abilities --}}
    <div>
        <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400 mb-2">Standard abilities</p>
        <flux:separator class="mb-3" />
        <div class="flex flex-wrap gap-x-6 gap-y-3">
            @foreach($standardAbilities as $ability)
                <flux:checkbox
                    wire:model="crudAbilities"
                    value="{{ $ability }}"
                    label="{{ $ability }}"
                />
            @endforeach
        </div>
    </div>

    {{-- Custom abilities --}}
    <div>
        <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400 mb-2">Custom abilities</p>
        <flux:separator class="mb-3" />

        @if(count($customAbilities) > 0)
            <div class="flex flex-wrap gap-2 mb-3">
                @foreach($customAbilities as $index => $ability)
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium
                                 bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300">
                        <span class="font-mono">{{ $ability }}</span>
                        <button wire:click="removeCustomAbility({{ $index }})"
                                type="button"
                                class="ml-0.5 leading-none text-amber-600 dark:text-amber-400 hover:text-amber-900 transition">×</button>
                    </span>
                @endforeach
            </div>
        @endif

        <div class="flex items-center gap-2">
            <flux:input
                wire:model="newCustomAbility"
                wire:keydown.enter.prevent="addCustomAbility"
                placeholder="e.g. export"
                class="font-mono w-48"
            />
            <flux:button wire:click="addCustomAbility" size="sm" variant="ghost">Add</flux:button>
        </div>
    </div>

    {{-- Footer --}}
    <div class="flex items-center justify-between pt-2">
        <flux:button wire:click="cancel" variant="ghost">Cancel</flux:button>
        <flux:button wire:click="save" variant="primary">Save</flux:button>
    </div>
</div>
