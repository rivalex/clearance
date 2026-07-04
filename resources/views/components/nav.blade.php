{{--
    Clearance shared panel navigation menu.

    $active must be passed explicitly by the including page (e.g. `active="roles"`) rather than
    derived here via request()->routeIs(): several top-level managers are Livewire #[Lazy]
    components, whose real render happens over Livewire's internal update request, not the
    original page route — request()->routeIs() would silently never match there.
--}}
@props(['active' => null])

@php
    $navItems = [
        [
            'key'   => 'home',
            'route' => 'clearance.home',
            'icon'  => 'home',
            'label' => __('clearance::ui.common.nav.dashboard'),
        ],
        [
            'key'   => 'permissions',
            'route' => 'clearance.permissions',
            'icon'  => 'key',
            'label' => __('clearance::ui.common.nav.permissions'),
        ],
        [
            'key'   => 'roles',
            'route' => 'clearance.roles',
            'icon'  => 'shield-check',
            'label' => __('clearance::ui.common.nav.roles'),
        ],
        [
            'key'   => 'guards',
            'route' => 'clearance.guards',
            'icon'  => 'lock-closed',
            'label' => __('clearance::ui.common.nav.guards'),
        ],
        [
            'key'   => 'settings',
            'route' => 'clearance.settings',
            'icon'  => 'cog-6-tooth',
            'label' => __('clearance::ui.common.nav.settings'),
        ],
    ];
@endphp

<div class="flex flex-wrap items-center gap-2 text-sm">
    @foreach ($navItems as $item)
        @php $isActive = $active === $item['key']; @endphp
        <flux:button
            href="{{ route($item['route']) }}"
            :variant="$isActive ? 'filled' : 'ghost'"
            size="sm"
            :icon="$item['icon']"
            :aria-current="$isActive ? 'page' : false">
            {{ $item['label'] }}
        </flux:button>
    @endforeach
</div>

<flux:separator class="my-2" />
