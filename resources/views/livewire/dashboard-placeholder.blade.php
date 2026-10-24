<div>
    <x-clearance::branding />

    <flux:skeleton.group animate="pulse">
        <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
            <div class="min-w-0">
                <flux:skeleton class="h-7 w-40" />
                <flux:skeleton class="mt-1 h-5 w-64" />
            </div>
        </div>

        <x-clearance::nav active="home" />

        <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
            <flux:card class="flex flex-col justify-between">
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <flux:skeleton class="h-4 w-24" />
                        <flux:skeleton class="h-5 w-5" />
                    </div>
                    <flux:skeleton class="h-9 w-16" />
                </div>
                <flux:skeleton class="mt-4 h-6 w-28" />
            </flux:card>
            <flux:card class="flex flex-col justify-between">
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <flux:skeleton class="h-4 w-32" />
                        <flux:skeleton class="h-5 w-5" />
                    </div>
                    <flux:skeleton class="h-9 w-16" />
                </div>
                <flux:skeleton class="mt-4 h-6 w-28" />
            </flux:card>
            <flux:card class="flex flex-col justify-between">
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <flux:skeleton class="h-4 w-24" />
                        <flux:skeleton class="h-5 w-5" />
                    </div>
                    <flux:skeleton class="h-9 w-16" />
                </div>
                <flux:skeleton class="mt-4 h-6 w-28" />
            </flux:card>
            <flux:card class="flex flex-col justify-between">
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <flux:skeleton class="h-4 w-32" />
                        <flux:skeleton class="h-5 w-5" />
                    </div>
                    <flux:skeleton class="h-9 w-16" />
                </div>
            </flux:card>

            <flux:card class="min-w-0 sm:col-span-2">
                <flux:skeleton class="h-7 w-40 mb-4" />
                <div class="space-y-3">
                    <flux:skeleton class="h-12 w-full" />
                    <flux:skeleton class="h-12 w-full" />
                    <flux:skeleton class="h-12 w-full" />
                </div>
            </flux:card>
            <flux:card class="min-w-0 sm:col-span-2">
                <flux:skeleton class="h-7 w-32 mb-4" />
                <div class="space-y-2">
                    <flux:skeleton class="h-10 w-full" />
                    <flux:skeleton class="h-10 w-full" />
                    <flux:skeleton class="h-10 w-full" />
                    <flux:skeleton class="h-10 w-full" />
                </div>
            </flux:card>
        </div>
    </flux:skeleton.group>
</div>
