<div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-6">
    <h2 class="text-base font-semibold mb-1">{{ __('clearance::ui.settings.roles_meta.title') }}</h2>
    <p class="text-sm text-zinc-500 dark:text-zinc-400 mb-4">{{ __('clearance::ui.settings.roles_meta.description') }}</p>

    <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
        <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700 text-sm">
            <thead class="bg-zinc-50 dark:bg-zinc-900">
                <tr>
                    <th scope="col" class="whitespace-nowrap px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('clearance::ui.common.role') }}</th>
                    <th scope="col" class="whitespace-nowrap px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('clearance::ui.settings.meta.display_name') }}</th>
                    <th scope="col" class="whitespace-nowrap px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('clearance::ui.settings.meta.icon') }}</th>
                    <th scope="col" class="whitespace-nowrap px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('clearance::ui.settings.meta.color') }}</th>
                    <th scope="col" class="whitespace-nowrap px-4 py-3 text-right text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('clearance::ui.common.actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                @forelse($roles as $role)
                    @php $meta = $roleMetas->get($role->name); @endphp
                    <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-700/50">
                        <td class="px-4 py-3 font-mono text-xs font-medium">
                            <div class="max-w-xs truncate" title="{{ $role->name }} ({{ $role->guard_name }})">
                                {{ $role->name }}
                                <span class="ml-1 text-zinc-500 dark:text-zinc-400">({{ $role->guard_name }})</span>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300">
                            <div class="max-w-xs truncate" title="{{ $meta?->display_name ?? '-' }}">{{ $meta?->display_name ?? '-' }}</div>
                        </td>
                        <td class="px-4 py-3">
                            @if($meta?->icon_svg)
                                <span class="inline-flex items-center justify-center w-6 h-6" style="{{ $meta->color ? 'color:'.\Rivalex\Clearance\Support\SvgSanitizer::safeCssColor($meta->color) : '' }}">{!! \Rivalex\Clearance\Support\SvgSanitizer::sanitize($meta->icon_svg) !!}</span>
                            @else
                                <span class="text-zinc-300 dark:text-zinc-600">-</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @if($meta?->color)
                                <span class="inline-flex items-center gap-2">
                                    <span class="inline-block w-4 h-4 rounded-full border border-zinc-200 dark:border-zinc-600" style="background:{{ \Rivalex\Clearance\Support\SvgSanitizer::safeCssColor($meta->color) }}"></span>
                                    <span class="font-mono text-xs text-zinc-500">{{ $meta->color }}</span>
                                </span>
                            @else
                                <span class="text-zinc-300 dark:text-zinc-600">-</span>
                            @endif
                        </td>
                        <td class="whitespace-nowrap px-4 py-3">
                            <div class="flex justify-end">
                                <livewire:clearance::settings.edit-meta
                                    subjectType="role"
                                    :subjectKey="$role->name"
                                    :key="'edit-meta-role-'.$role->name" />
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('clearance::ui.settings.roles_meta.empty') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
