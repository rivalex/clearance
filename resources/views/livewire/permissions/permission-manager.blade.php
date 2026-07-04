@php use Illuminate\Support\Str; @endphp
<div class="clearance">
	<x-clearance::branding/>
	{{-- Header --}}
	<div class="mb-6 flex items-center justify-between">
		<div class="flex flex-col">
			<h1 class="text-xl font-semibold">{{ __('clearance::ui.permissions.title') }}</h1>
			<p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('clearance::ui.permissions.description') }}</p>
		</div>
		<livewire:clearance::permissions.new-permission wire:key="new-permission"/>
	</div>

	<x-clearance::nav active="permissions" />

	{{-- Search --}}
	<div class="mb-4">
		<flux:input
				wire:model.live.debounce.300ms="search"
				type="search"
				placeholder="{{ __('clearance::ui.permissions.search_placeholder') }}"
				class="max-w-sm"
		>
			<x-slot name="icon">
				<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
				     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
				     class="size-4">
					<circle cx="11" cy="11" r="8"/>
					<path d="m21 21-4.3-4.3"/>
				</svg>
			</x-slot>
		</flux:input>
	</div>
	
	{{-- Grouped permission cards --}}
	<div class="space-y-3">
		@forelse($groupedPermissions as $groupData)
			<livewire:clearance::permissions.permission-group-row
					:group="$groupData['group']"
					:guard="$groupData['guard']"
					:groupKey="$groupData['groupKey']"
					:key="'group-row-'.$groupData['groupKey']"
					lazy
			/>
		@empty
			<div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 px-4 py-8 text-center text-sm text-gray-400">
				@if($search !== '')
					{{ __('clearance::ui.permissions.no_match', ['search' => $search]) }}
				@else
					{{ __('clearance::ui.permissions.empty') }}
				@endif
			</div>
		@endforelse
	</div>
	
	{{-- Pagination --}}
	@if($groupedPermissions->hasPages())
		<div class="mt-4">
			{{ $groupedPermissions->links() }}
		</div>
	@endif
</div>

@assets
@once
	<link rel="stylesheet" href="{{ route('clearance.assets', 'css/clearance.min.css') }}">
@endonce
@endassets
