<div>
	<x-clearance::branding />
	<div class="mb-6 flex flex-wrap items-center justify-between gap-4">
		<div class="min-w-0">
			<h1 class="text-xl font-semibold">{{ __('clearance::ui.dashboard.title') }}</h1>
			<p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ __('clearance::ui.dashboard.description') }}</p>
		</div>
	</div>

	<x-clearance::nav active="home" />

	<div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
		<flux:card class="flex flex-col justify-between">
			<div>
				<div class="flex items-center justify-between mb-2">
					<span class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('clearance::ui.dashboard.stats.total_roles') }}</span>
					<x-clearance::icon.shield-user class="w-5 h-5 text-zinc-400 dark:text-zinc-500" />
				</div>
				<div class="text-3xl font-bold">{{ $stats['roles_count'] }}</div>
			</div>
			<div class="mt-4">
				<flux:button href="{{ route('clearance.roles') }}" variant="ghost"
				             size="xs">{{ __('clearance::ui.dashboard.actions.manage_roles') }}</flux:button>
			</div>
		</flux:card>

		<flux:card class="flex flex-col justify-between">
			<div>
				<div class="flex items-center justify-between mb-2">
					<span class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('clearance::ui.dashboard.stats.permission_groups') }}</span>
					<x-clearance::icon.group class="w-5 h-5 text-zinc-400 dark:text-zinc-500" />
				</div>
				<div class="text-3xl font-bold">{{ $stats['groups_count'] }}</div>
				<div class="text-xs text-zinc-500 dark:text-zinc-400 mt-1">{{ __('clearance::ui.dashboard.stats.total_abilities', ['count' => $stats['permissions_count']]) }}</div>
			</div>
			<div class="mt-4">
				<flux:button href="{{ route('clearance.permissions') }}" variant="ghost"
				             size="xs">{{ __('clearance::ui.dashboard.actions.manage_permissions') }}</flux:button>
			</div>
		</flux:card>

		<flux:card class="flex flex-col justify-between">
			<div>
				<div class="flex items-center justify-between mb-2">
					<span class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('clearance::ui.dashboard.stats.active_guards') }}</span>
					<x-clearance::icon.brick-wall-shield class="w-5 h-5 text-zinc-400 dark:text-zinc-500" />
				</div>
				<div class="text-3xl font-bold">{{ $stats['guards_count'] }}</div>
			</div>
			<div class="mt-4">
				<flux:button href="{{ route('clearance.guards') }}" variant="ghost"
				             size="xs">{{ __('clearance::ui.dashboard.actions.configure_guards') }}</flux:button>
			</div>
		</flux:card>

		<flux:card class="flex flex-col justify-between">
			<div>
				<div class="flex items-center justify-between mb-2">
					<span class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('clearance::ui.dashboard.stats.contextual_assignments') }}</span>
					<x-clearance::icon.users class="w-5 h-5 text-zinc-400 dark:text-zinc-500" />
				</div>
				<div class="text-3xl font-bold">{{ $stats['user_contexts_count'] }}</div>
			</div>
		</flux:card>

		<flux:card class="min-w-0 sm:col-span-2">
			<h3 class="text-lg font-medium mb-4">{{ __('clearance::ui.dashboard.tables.top_roles_title') }}</h3>
			<div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
				<table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700 text-sm">
					<thead class="bg-zinc-50 dark:bg-zinc-900">
						<tr>
							<th scope="col" class="whitespace-nowrap px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('clearance::ui.dashboard.tables.role') }}</th>
							<th scope="col" class="whitespace-nowrap px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('clearance::ui.dashboard.tables.guard') }}</th>
							<th scope="col" class="whitespace-nowrap px-4 py-3 text-right text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('clearance::ui.dashboard.tables.users') }}</th>
						</tr>
					</thead>
					<tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
						@foreach($users_per_role as $role)
							<tr class="hover:bg-zinc-50 dark:hover:bg-zinc-700/50">
								<td class="px-4 py-3 font-mono text-xs font-medium"><div class="max-w-xs truncate" title="{{ $role->name }}">{{ $role->name }}</div></td>
								<td class="px-4 py-3 font-mono text-xs"><div class="max-w-xs truncate" title="{{ $role->guard_name }}">{{ $role->guard_name }}</div></td>
								<td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">
									<flux:badge size="sm" color="zinc">{{ $role->users_count }}</flux:badge>
								</td>
							</tr>
						@endforeach
						@if($users_per_role->isEmpty())
							<tr>
								<td colspan="3" class="px-4 py-8 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('clearance::ui.dashboard.tables.no_data') }}</td>
							</tr>
						@endif
					</tbody>
				</table>
			</div>
		</flux:card>

		<flux:card class="min-w-0 sm:col-span-2">
			<h3 class="text-lg font-medium mb-4">{{ __('clearance::ui.dashboard.quick_links.title') }}</h3>
			<div class="space-y-2">
				<flux:button href="{{ route('clearance.roles') }}" variant="ghost" class="w-full justify-start gap-4">
					<x-clearance::icon.shield-user class="w-5 h-5 text-zinc-400 dark:text-zinc-500" />
					{{ __('clearance::ui.dashboard.quick_links.roles_list') }}
				</flux:button>
				<flux:button href="{{ route('clearance.permissions') }}" variant="ghost" class="w-full justify-start gap-4">
					<x-clearance::icon.group class="w-5 h-5 text-zinc-400 dark:text-zinc-500" />
					{{ __('clearance::ui.dashboard.quick_links.permissions_list') }}
				</flux:button>
				<flux:button href="{{ route('clearance.guards') }}" variant="ghost" class="w-full justify-start gap-4">
					<x-clearance::icon.brick-wall-shield class="w-5 h-5 text-zinc-400 dark:text-zinc-500" />
					{{ __('clearance::ui.dashboard.quick_links.guards_config') }}
				</flux:button>
				<flux:button href="{{ route('clearance.settings') }}" variant="ghost" class="w-full justify-start gap-4">
					<x-clearance::icon.settings class="w-5 h-5 text-zinc-400 dark:text-zinc-500" />
					{{ __('clearance::ui.dashboard.quick_links.settings') }}
				</flux:button>
			</div>
		</flux:card>
	</div>
</div>

@assets
@once
@endonce
@endassets
