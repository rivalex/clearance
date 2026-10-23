<div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-6">
    <h2 class="text-base font-semibold mb-1">{{ __('clearance::ui.settings.roles_meta.title') }}</h2>
    <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">{{ __('clearance::ui.settings.roles_meta.description') }}</p>

    <div class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
            <thead class="bg-gray-50 dark:bg-gray-900">
                <tr>
                    <th class="px-4 py-3 text-left font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide text-xs">{{ __('clearance::ui.common.role') }}</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide text-xs">{{ __('clearance::ui.settings.meta.display_name') }}</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide text-xs">{{ __('clearance::ui.settings.meta.icon') }}</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide text-xs">{{ __('clearance::ui.settings.meta.color') }}</th>
                    <th class="px-4 py-3 text-right font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide text-xs">{{ __('clearance::ui.common.actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse($roles as $role)
                    @php $meta = $roleMetas->get($role->name); @endphp
                    <tr>
                        <td class="px-4 py-3 font-mono text-xs font-medium">
                            {{ $role->name }}
                            <span class="ml-1 text-gray-400">({{ $role->guard_name }})</span>
                        </td>
                        <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ $meta?->display_name ?? '-' }}</td>
                        <td class="px-4 py-3">
                            @if($meta?->icon_svg)
                                <span class="inline-flex items-center justify-center w-6 h-6" style="{{ $meta->color ? 'color:'.\Rivalex\Clearance\Support\SvgSanitizer::safeCssColor($meta->color) : '' }}">{!! \Rivalex\Clearance\Support\SvgSanitizer::sanitize($meta->icon_svg) !!}</span>
                            @else
                                <span class="text-gray-300 dark:text-gray-600">-</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @if($meta?->color)
                                <span class="inline-flex items-center gap-2">
                                    <span class="inline-block w-4 h-4 rounded-full border border-gray-200 dark:border-gray-600" style="background:{{ \Rivalex\Clearance\Support\SvgSanitizer::safeCssColor($meta->color) }}"></span>
                                    <span class="font-mono text-xs text-gray-500">{{ $meta->color }}</span>
                                </span>
                            @else
                                <span class="text-gray-300 dark:text-gray-600">-</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
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
                        <td colspan="5" class="px-4 py-6 text-center text-gray-400">{{ __('clearance::ui.settings.roles_meta.empty') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
