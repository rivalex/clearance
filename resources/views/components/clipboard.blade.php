@props([
    'textToCopy',
	'size' => 'size-4',
    'showTooltip' => true
    ])
<div x-data="{
		textToCopy: @js($textToCopy),
		copied: false,
		keyCopied() {
			this.copied = true;
			setTimeout(() => this.copied = false, 1000);
		},
		decodeHtml(html) {
            var txt = document.createElement('textarea');
            txt.innerHTML = html;
            return txt.value;
        }
	}" {{ $attributes->class(['flex flex-row items-center']) }}
>
	<button tabindex="-1" class="cursor-pointer" type="button" id="{{ rand() }}"
	        x-on:click.stop="await navigator.clipboard.writeText(decodeHtml(textToCopy)); keyCopied();">
		<div class="flex flex-row items-center select-none text-start">
			@if($showTooltip)
				<flux:tooltip content="{{ __('clearance::ui.common.click_to_copy') }}">
					<div class="flex">
						<div x-cloak x-show="!copied">
							<svg class="text-sky-600 dark:text-sky-400 {{ $size }}" xmlns="http://www.w3.org/2000/svg"
							     width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
							     stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
								<rect width="14" height="14" x="8" y="8" rx="2" ry="2"/>
								<path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>
							</svg>
						</div>
						<div x-cloak x-show="copied">
							<svg class="text-green-700 dark:text-green-500 {{ $size }}" xmlns="http://www.w3.org/2000/svg"
							     width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
							     stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
								<path d="M20 6 9 17l-5-5"/>
							</svg>
						</div>
					</div>
				</flux:tooltip>
			@else
				<div class="flex">
					<div x-cloak x-show="!copied">
						<svg class="text-sky-600 dark:text-sky-400 {{ $size }}" xmlns="http://www.w3.org/2000/svg" width="24"
						     height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
						     stroke-linecap="round" stroke-linejoin="round">
							<rect width="14" height="14" x="8" y="8" rx="2" ry="2"/>
							<path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>
						</svg>
					</div>
					<div x-cloak x-show="copied">
						<svg class="text-green-700 dark:text-green-500 {{ $size }}" xmlns="http://www.w3.org/2000/svg"
						     width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
						     stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
							<path d="M20 6 9 17l-5-5"/>
						</svg>
					</div>
				</div>
			@endif
			<div>
				{{ $slot }}
			</div>
		</div>
	</button>
</div>