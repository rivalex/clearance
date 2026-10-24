@props(['columns' => 5, 'rows' => 5])

<div {{ $attributes }}>
    <flux:skeleton.group animate="pulse">
        <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800">
            <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700 text-sm">
                <thead class="bg-zinc-50 dark:bg-zinc-900">
                    <tr>
                        @for($i = 0; $i < $columns; $i++)
                            <th scope="col" class="whitespace-nowrap px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                <flux:skeleton class="h-3 w-16" />
                            </th>
                        @endfor
                    </tr>
                </thead>

                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                    @for($j = 0; $j < $rows; $j++)
                        <tr>
                            @for($i = 0; $i < $columns; $i++)
                                <td class="px-4 py-3">
                                    <flux:skeleton.line />
                                </td>
                            @endfor
                        </tr>
                    @endfor
                </tbody>
            </table>
        </div>
    </flux:skeleton.group>
</div>
