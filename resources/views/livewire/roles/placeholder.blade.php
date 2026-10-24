<div>
    <x-clearance::branding />
    <flux:skeleton.group animate="pulse">
        <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
            <div class="min-w-0">
                <flux:skeleton class="h-7 w-32" />
                <flux:skeleton class="mt-1 h-5 w-64" />
            </div>
            <flux:skeleton class="h-8 w-24" />
        </div>

        <x-clearance::nav active="roles" />

        <div class="mb-4">
            <flux:skeleton class="h-10 w-full max-w-sm rounded-lg" />
        </div>

        <x-clearance::table-placeholder :columns="6" :rows="10" />
    </flux:skeleton.group>
</div>
