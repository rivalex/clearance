<div class="clearance">
	<x-clearance::branding />
	<div class="mb-6">
		<h1 class="text-xl font-semibold">{{ __('clearance::ui.dashboard.title') }}</h1>
		<p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('clearance::ui.dashboard.description') }}</p>
	</div>

	<x-clearance::nav active="home" />

	<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
		<flux:card class="flex flex-col justify-between">
			<div>
				<div class="flex items-center justify-between mb-2">
					<span class="text-sm font-medium text-gray-500">{{ __('clearance::ui.dashboard.stats.total_roles') }}</span>
					<x-clearance::icon.shield-user class="w-5 h-5 text-gray-400 dark:text-gray-500" />
				</div>
				<div class="text-3xl font-bold">{{ $stats['roles_count'] }}</div>
			</div>
			<div class="mt-4">
				<flux:button href="{{ route('clearance.roles') }}" variant="primary"
				             size="xs">{{ __('clearance::ui.dashboard.actions.manage_roles') }}</flux:button>
			</div>
		</flux:card>

		<flux:card class="flex flex-col justify-between">
			<div>
				<div class="flex items-center justify-between mb-2">
					<span class="text-sm font-medium text-gray-500">{{ __('clearance::ui.dashboard.stats.permission_groups') }}</span>
					<x-clearance::icon.group class="w-5 h-5 text-gray-400 dark:text-gray-500" />
				</div>
				<div class="text-3xl font-bold">{{ $stats['groups_count'] }}</div>
				<div class="text-xs text-gray-400 mt-1">{{ __('clearance::ui.dashboard.stats.total_abilities', ['count' => $stats['permissions_count']]) }}</div>
			</div>
			<div class="mt-4">
				<flux:button href="{{ route('clearance.permissions') }}" variant="primary"
				             size="xs">{{ __('clearance::ui.dashboard.actions.manage_permissions') }}</flux:button>
			</div>
		</flux:card>

		<flux:card class="flex flex-col justify-between">
			<div>
				<div class="flex items-center justify-between mb-2">
					<span class="text-sm font-medium text-gray-500">{{ __('clearance::ui.dashboard.stats.active_guards') }}</span>
					<x-clearance::icon.brick-wall-shield class="w-5 h-5 text-gray-400 dark:text-gray-500" />
				</div>
				<div class="text-3xl font-bold">{{ $stats['guards_count'] }}</div>
			</div>
			<div class="mt-4">
				<flux:button href="{{ route('clearance.guards') }}" variant="primary"
				             size="xs">{{ __('clearance::ui.dashboard.actions.configure_guards') }}</flux:button>
			</div>
		</flux:card>

		<flux:card class="flex flex-col justify-between">
			<div>
				<div class="flex items-center justify-between mb-2">
					<span class="text-sm font-medium text-gray-500">{{ __('clearance::ui.dashboard.stats.contextual_assignments') }}</span>
					<x-clearance::icon.users class="w-5 h-5 text-gray-400 dark:text-gray-500" />
				</div>
				<div class="text-3xl font-bold">{{ $stats['user_contexts_count'] }}</div>
			</div>
		</flux:card>
	</div>

	<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
		<flux:card>
			<h3 class="text-lg font-medium mb-4">{{ __('clearance::ui.dashboard.tables.top_roles_title') }}</h3>
			<div class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700">
				<table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
					<thead class="bg-gray-50 dark:bg-gray-900">
						<tr>
							<th class="px-4 py-3 text-left font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide text-xs">{{ __('clearance::ui.dashboard.tables.role') }}</th>
							<th class="px-4 py-3 text-left font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide text-xs">{{ __('clearance::ui.dashboard.tables.guard') }}</th>
							<th class="px-4 py-3 text-right font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide text-xs">{{ __('clearance::ui.dashboard.tables.users') }}</th>
						</tr>
					</thead>
					<tbody class="divide-y divide-gray-100 dark:divide-gray-700">
						@foreach($users_per_role as $role)
							<tr>
								<td class="px-4 py-3 font-mono text-xs font-medium">{{ $role->name }}</td>
								<td class="px-4 py-3 font-mono text-xs">{{ $role->guard_name }}</td>
								<td class="px-4 py-3 text-right">
									<flux:badge size="sm" color="zinc">{{ $role->users_count }}</flux:badge>
								</td>
							</tr>
						@endforeach
						@if($users_per_role->isEmpty())
							<tr>
								<td colspan="3" class="px-4 py-6 text-center text-gray-400">{{ __('clearance::ui.dashboard.tables.no_data') }}</td>
							</tr>
						@endif
					</tbody>
				</table>
			</div>
		</flux:card>

		<flux:card>
			<h3 class="text-lg font-medium mb-4">{{ __('clearance::ui.dashboard.quick_links.title') }}</h3>
			<div class="space-y-2">
				<flux:button href="{{ route('clearance.roles') }}" variant="ghost" class="w-full justify-start gap-4">
					<x-clearance::icon.shield-user class="w-5 h-5 text-gray-400 dark:text-gray-500" />
					{{ __('clearance::ui.dashboard.quick_links.roles_list') }}
				</flux:button>
				<flux:button href="{{ route('clearance.permissions') }}" variant="ghost" class="w-full justify-start gap-4">
					<x-clearance::icon.group class="w-5 h-5 text-gray-400 dark:text-gray-500" />
					{{ __('clearance::ui.dashboard.quick_links.permissions_list') }}
				</flux:button>
				<flux:button href="{{ route('clearance.guards') }}" variant="ghost" class="w-full justify-start gap-4">
					<x-clearance::icon.brick-wall-shield class="w-5 h-5 text-gray-400 dark:text-gray-500" />
					{{ __('clearance::ui.dashboard.quick_links.guards_config') }}
				</flux:button>
				<flux:button href="{{ route('clearance.settings') }}" variant="ghost" class="w-full justify-start gap-4">
					<x-clearance::icon.settings class="w-5 h-5 text-gray-400 dark:text-gray-500" />
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
