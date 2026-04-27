<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'Clearance') }} — Access Panel</title>

    {{-- Tailwind 4 CDN — self-contained, no host app dependency --}}
    <script src="https://unpkg.com/@tailwindcss/browser@4"></script>

    @livewireStyles

    @if(config('clearance.ui.flux_pro') || (class_exists(\Flux\Flux::class) && \Flux\Flux::pro()))
        @fluxStyles
    @endif
</head>
<body class="min-h-screen bg-zinc-50 dark:bg-zinc-900 text-zinc-900 dark:text-zinc-100 antialiased">

    <div class="min-h-screen flex flex-col">
        <header class="bg-white dark:bg-zinc-800 border-b border-zinc-200 dark:border-zinc-700 px-6 py-4">
            <div class="max-w-7xl mx-auto flex items-center gap-3">
                <span class="font-semibold text-sm text-zinc-500 dark:text-zinc-400 uppercase tracking-wide">
                    {{ config('app.name', 'App') }}
                </span>
                <span class="text-zinc-300 dark:text-zinc-600">/</span>
                <span class="font-semibold text-sm">Access Panel</span>
            </div>
        </header>

        <main class="flex-1 max-w-7xl w-full mx-auto px-6 py-8">
            {{ $slot }}
        </main>
    </div>

    @livewireScripts

    @if(config('clearance.ui.flux_pro') || (class_exists(\Flux\Flux::class) && \Flux\Flux::pro()))
        @fluxScripts
    @endif
</body>
</html>
