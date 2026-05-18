<div class="clearance">
    <x-clearance::branding />
    <flux:skeleton.group animate="pulse">
        <div class="mb-6 flex items-center justify-between">
            <div>
                <flux:skeleton class="h-7 w-24 mb-2" />
                <flux:skeleton class="h-4 w-64" />
            </div>
            <flux:skeleton class="h-9 w-24" />
        </div>

        <x-clearance::table-placeholder :columns="5" :rows="5" />
    </flux:skeleton.group>
</div>
