<div class="clearance">
	<x-clearance::branding />
	<div class="mb-6">
		<h1 class="text-2xl font-semibold">{{ __('clearance::ui.dashboard.title') }}</h1>
		<p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ __('clearance::ui.dashboard.description') }}</p>
	</div>

	<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
		<flux:card class="flex flex-col justify-between">
			<div>
				<div class="flex items-center justify-between mb-2">
					<span class="text-sm font-medium text-zinc-500">{{ __('clearance::ui.dashboard.stats.total_roles') }}</span>
					<x-clearance::icon.shield-user class="w-5 h-5 text-sky-500" />
				</div>
				<div class="text-3xl font-bold">{{ $stats['roles_count'] }}</div>
			</div>
			<div class="mt-4">
				<flux:button href="{{ route('clearance.roles') }}" variant="primary" color="sky"
				             size="xs">{{ __('clearance::ui.dashboard.actions.manage_roles') }}</flux:button>
			</div>
		</flux:card>

		<flux:card class="flex flex-col justify-between">
			<div>
				<div class="flex items-center justify-between mb-2">
					<span class="text-sm font-medium text-zinc-500">{{ __('clearance::ui.dashboard.stats.permission_groups') }}</span>
					<x-clearance::icon.group class="w-5 h-5 text-amber-500" />
				</div>
				<div class="text-3xl font-bold">{{ $stats['groups_count'] }}</div>
				<div class="text-xs text-zinc-400 mt-1">{{ __('clearance::ui.dashboard.stats.total_abilities', ['count' => $stats['permissions_count']]) }}</div>
			</div>
			<div class="mt-4">
				<flux:button href="{{ route('clearance.permissions') }}" variant="primary" color="amber"
				             size="xs">{{ __('clearance::ui.dashboard.actions.manage_permissions') }}</flux:button>
			</div>
		</flux:card>

		<flux:card class="flex flex-col justify-between">
			<div>
				<div class="flex items-center justify-between mb-2">
					<span class="text-sm font-medium text-zinc-500">{{ __('clearance::ui.dashboard.stats.active_guards') }}</span>
					<x-clearance::icon.brick-wall-shield class="w-5 h-5 text-emerald-500" />
				</div>
				<div class="text-3xl font-bold">{{ $stats['guards_count'] }}</div>
			</div>
			<div class="mt-4">
				<flux:button href="{{ route('clearance.guards') }}" variant="primary" color="emerald"
				             size="xs">{{ __('clearance::ui.dashboard.actions.configure_guards') }}</flux:button>
			</div>
		</flux:card>

		<flux:card class="flex flex-col justify-between">
			<div>
				<div class="flex items-center justify-between mb-2">
					<span class="text-sm font-medium text-zinc-500">{{ __('clearance::ui.dashboard.stats.contextual_assignments') }}</span>
					<x-clearance::icon.users class="w-5 h-5 text-rose-500" />
				</div>
				<div class="text-3xl font-bold">{{ $stats['user_contexts_count'] }}</div>
			</div>
{{--			<div class="mt-4">--}}
{{--				@if(config('clearance.modules.users'))--}}
{{--					<flux:button href="{{ route('clearance.users') }}" variant="primary" color="rose"--}}
{{--					             size="xs">{{ __('clearance::ui.dashboard.actions.manage_users') }}</flux:button>--}}
{{--				@endif--}}
{{--			</div>--}}
		</flux:card>
	</div>

	<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
		<flux:card>
			<h3 class="text-lg font-medium mb-4">{{ __('clearance::ui.dashboard.tables.top_roles_title') }}</h3>
			<flux:table>
				<flux:table.columns>
					<flux:table.column>{{ __('clearance::ui.dashboard.tables.role') }}</flux:table.column>
					<flux:table.column>{{ __('clearance::ui.dashboard.tables.guard') }}</flux:table.column>
					<flux:table.column align="right">{{ __('clearance::ui.dashboard.tables.users') }}</flux:table.column>
				</flux:table.columns>
				<flux:table.rows>
					@foreach($users_per_role as $role)
						<flux:table.row>
							<flux:table.cell class="font-medium">{{ $role->name }}</flux:table.cell>
							<flux:table.cell>{{ $role->guard_name }}</flux:table.cell>
							<flux:table.cell align="right">
								<flux:badge size="sm" color="zinc">{{ $role->users_count }}</flux:badge>
							</flux:table.cell>
						</flux:table.row>
					@endforeach
					@if($users_per_role->isEmpty())
						<flux:table.row>
							<flux:table.cell colspan="3" class="text-center text-zinc-400 py-4">{{ __('clearance::ui.dashboard.tables.no_data') }}</flux:table.cell>
						</flux:table.row>
					@endif
				</flux:table.rows>
			</flux:table>
		</flux:card>

		<flux:card>
			<h3 class="text-lg font-medium mb-4">{{ __('clearance::ui.dashboard.quick_links.title') }}</h3>
			<div class="space-y-2">
				<flux:button href="{{ route('clearance.roles') }}" variant="ghost" class="w-full justify-start gap-4">
					<x-clearance::icon.shield-user class="w-5 h-5 text-sky-500" />
					{{ __('clearance::ui.dashboard.quick_links.roles_list') }}
				</flux:button>
				<flux:button href="{{ route('clearance.permissions') }}" variant="ghost" class="w-full justify-start gap-4">
					<x-clearance::icon.group class="w-5 h-5 text-amber-500" />
					{{ __('clearance::ui.dashboard.quick_links.permissions_list') }}
				</flux:button>
				<flux:button href="{{ route('clearance.guards') }}" variant="ghost" class="w-full justify-start gap-4">
					<x-clearance::icon.brick-wall-shield class="w-5 h-5 text-emerald-500" />
					{{ __('clearance::ui.dashboard.quick_links.guards_config') }}
				</flux:button>
{{--				@if(config('clearance.modules.users'))--}}
{{--					<flux:button href="{{ route('clearance.users') }}" variant="ghost" class="w-full justify-start gap-4">--}}
{{--						<x-clearance::icon.users class="w-5 h-5 text-rose-500" />--}}
{{--						{{ __('clearance::ui.dashboard.actions.manage_users') }}--}}
{{--					</flux:button>--}}
{{--				@endif--}}
				<flux:button href="{{ route('clearance.settings') }}" variant="ghost" class="w-full justify-start gap-4">
					<x-clearance::icon.settings class="w-5 h-5 text-zinc-500" />
					{{ __('clearance::ui.dashboard.quick_links.settings') }}
				</flux:button>
			</div>
		</flux:card>
	</div>
</div>

@assets
@once
	<link rel="stylesheet" href="{{ route('clearance.assets', 'css/clearance.min.css') }}">
@endonce
@endassets
