<div>
    <div class="mb-6">
        <h1 class="text-xl font-semibold">Guards</h1>
        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Authentication guards configured in your application.</p>
    </div>

    <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800">
        <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700 text-sm">
            <thead class="bg-zinc-50 dark:bg-zinc-900">
                <tr>
                    <th class="px-4 py-3 text-left font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide text-xs">Guard</th>
                    <th class="px-4 py-3 text-left font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide text-xs">Driver</th>
                    <th class="px-4 py-3 text-left font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide text-xs">Provider</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                @forelse($guards as $name => $config)
                    <tr>
                        <td class="px-4 py-3 font-mono text-xs font-medium">{{ $name }}</td>
                        <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300">{{ $config['driver'] ?? '—' }}</td>
                        <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300">{{ $config['provider'] ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="px-4 py-6 text-center text-zinc-400">No guards configured.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
