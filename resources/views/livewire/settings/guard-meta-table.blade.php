<div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-6">
    <h2 class="text-base font-semibold mb-1">{{ __('clearance::ui.settings.guards_meta.title') }}</h2>
    <p class="text-sm text-zinc-500 dark:text-zinc-400 mb-4">{{ __('clearance::ui.settings.guards_meta.description') }}</p>

    <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
        <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700 text-sm">
            <thead class="bg-zinc-50 dark:bg-zinc-900">
                <tr>
                    <th class="px-4 py-3 text-left font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide text-xs">{{ __('clearance::ui.common.guard') }}</th>
                    <th class="px-4 py-3 text-left font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide text-xs">{{ __('clearance::ui.settings.meta.display_name') }}</th>
                    <th class="px-4 py-3 text-left font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide text-xs">{{ __('clearance::ui.settings.meta.icon') }}</th>
                    <th class="px-4 py-3 text-left font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide text-xs">{{ __('clearance::ui.settings.meta.color') }}</th>
                    <th class="px-4 py-3 text-right font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide text-xs">{{ __('clearance::ui.common.actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                @forelse($guards as $guardName => $guardConfig)
                    @php $meta = $guardMetas->get($guardName); @endphp
                    <tr>
                        <td class="px-4 py-3 font-mono text-xs font-medium">{{ $guardName }}</td>
                        <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300">{{ $meta?->display_name ?? '—' }}</td>
                        <td class="px-4 py-3">
                            @if($meta?->icon_svg)
                                <span class="inline-flex items-center justify-center w-6 h-6" style="{{ $meta->color ? 'color:'.$meta->color : '' }}">{!! $meta->icon_svg !!}</span>
                            @else
                                <span class="text-zinc-300 dark:text-zinc-600">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @if($meta?->color)
                                <span class="inline-flex items-center gap-2">
                                    <span class="inline-block w-4 h-4 rounded-full border border-zinc-200 dark:border-zinc-600" style="background:{{ $meta->color }}"></span>
                                    <span class="font-mono text-xs text-zinc-500">{{ $meta->color }}</span>
                                </span>
                            @else
                                <span class="text-zinc-300 dark:text-zinc-600">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            <livewire:clearance::settings.edit-meta
                                subjectType="guard"
                                :subjectKey="$guardName"
                                :key="'edit-meta-guard-'.$guardName" />
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-6 text-center text-zinc-400">{{ __('clearance::ui.settings.guards_meta.empty') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
