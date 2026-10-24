{{-- Clearance branding: fixed watermark (mark) + inline logo (horizontal) --}}
@once
<div class="fixed top-0 right-0 w-72 opacity-[0.042] pointer-events-none select-none blur-[3px] rotate-20 z-0"
     aria-hidden="true">
    <img src="{{ \Rivalex\Clearance\Support\Asset::url('images/clearance-mark.svg') }}"
         alt=""
         width="288"
         height="227"
         class="w-full h-auto block [color-scheme:light] dark:[color-scheme:dark]">
</div>
@endonce

<div class="flex justify-start mb-4">
    <img src="{{ \Rivalex\Clearance\Support\Asset::url('images/clearance-mark-horizontal.svg') }}"
         alt="Clearance"
         width="auto"
         height="32"
         class="h-8 w-auto block [color-scheme:light] dark:[color-scheme:dark]">
</div>

<flux:separator class="mb-4"/>
