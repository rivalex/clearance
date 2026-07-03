@if ($errors->any())
<div {{ $attributes->class(['rounded-md bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 mb-4 p-4']) }}>
    <div class="flex gap-3">
        <span class="shrink-0 text-red-500 dark:text-red-400 text-base leading-5">⚠</span>
        <div>
            <p class="text-sm font-medium text-red-800 dark:text-red-300">
                {{ trans_choice('{1} There is :count error.|[2,*] There are :count errors.', count($errors->all()), ['count' => count($errors->all())]) }}
            </p>
            <ul class="mt-2 text-sm text-red-700 dark:text-red-400 list-disc list-inside space-y-1">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    </div>
</div>
@endif
