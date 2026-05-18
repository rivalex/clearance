<div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800">
    {{-- Group header row --}}
    <div class="flex items-center justify-between px-4 py-2.5 border-b border-zinc-100 dark:border-zinc-700">
        <div class="flex items-baseline gap-4">
            <h2 class="text-lg font-bold">{{ $labels['group'] }}</h2>
            <span class="text-sm text-zinc-400">{{ $labels['guard'] }}</span>
            <span class="text-xs text-zinc-400">{{ trans_choice('clearance::ui.permissions.count', count($abilities), ['count' => count($abilities)]) }}</span>
        </div>
        <div class="flex items-center gap-2">
            <livewire:clearance::permissions.edit-permission :prefix="$prefix" :groupKey="$groupKey" :guard="$guard" :key="'edit-'.$prefix.'-'.rand()" />
            <livewire:clearance::permissions.delete-permission :prefix="$prefix" :groupKey="$groupKey" :guard="$guard" :key="'delete-'.$prefix.'-'.$guard" />
        </div>
    </div>
    {{-- Ability badges --}}
    <div class="px-4 py-3 flex flex-wrap gap-2" key="{{ 'abilities-'.$groupKey }}">
        @foreach($abilities as $ability)
            <flux:badge :color="$ability['color']" :key="'ability-'.$prefix.'-'.$ability['name']">
                <div class="flex items-center gap-2">
                    <h3 class="text-xs font-light">{{ $ability['label'] }}</h3>
                    <x-clearance::clipboard size="size-3" text-to-copy="{{ $prefix . $sep . $ability['name'] }}" />
                </div>
            </flux:badge>
        @endforeach
    </div>
</div>
