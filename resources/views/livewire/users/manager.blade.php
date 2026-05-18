<div class="clearance">
    <x-clearance::branding />

    <div class="mb-6">
        <h1 class="text-xl font-semibold">{{ __('clearance::ui.user_clearance.title') }}</h1>
        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
            {{ __('clearance::ui.user_clearance.subtitle', [
                'user' => $user->name ?? $user->email ?? '#' . $user->getKey(),
            ]) }}
        </p>
    </div>

    @if(! $modulesEnabled)
        <flux:card>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                {{ __('clearance::ui.user_clearance.module_disabled') }}
            </p>
        </flux:card>
    @else
        {{-- Global Roles Panel --}}
        <div class="mb-6">
            <livewire:clearance::users.global-roles-panel
                :userId="$user->getKey()"
                :key="'grp-' . $user->getKey()" />
        </div>

        {{-- Contextual Panels (one per registered context model) --}}
        @foreach($contextualModels as $modelClass => $modelConfig)
            <div class="mb-6">
                <livewire:clearance::users.contextual-roles-panel
                    :userId="$user->getKey()"
                    :contextClass="$modelClass"
                    :key="'crp-' . md5($modelClass) . '-' . $user->getKey()" />
            </div>
        @endforeach
    @endif
</div>

@assets
@once
    <link rel="stylesheet" href="{{ route('clearance.assets', 'css/clearance.min.css') }}">
@endonce
@endassets
