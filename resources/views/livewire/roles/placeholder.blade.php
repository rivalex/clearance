<div class="clearance">
    <x-clearance::branding />
    <flux:skeleton.group animate="pulse">
        <div class="mb-6 flex items-center justify-between">
            <div>
                <flux:skeleton class="h-7 w-32 mb-2" />
                <flux:skeleton class="h-4 w-64" />
            </div>
            <flux:skeleton class="h-9 w-24" />
        </div>

        <div class="mb-4">
            <flux:skeleton class="h-10 w-full max-w-sm rounded-lg" />
        </div>

        <x-clearance::table-placeholder :columns="6" :rows="10" />
    </flux:skeleton.group>
</div>
