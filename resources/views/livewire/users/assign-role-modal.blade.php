<div class="clearance">
    <flux:modal name="{{ $modalName }}" class="min-w-96 md:min-w-xl">
        <div class="space-y-4">
            <div class="pb-4 border-b border-gray-200 dark:border-gray-700">
                <flux:heading size="lg">{{ __('clearance::ui.user_clearance.assign.title') }}</flux:heading>
            </div>

            @if($errorMessage)
                <div class="rounded-md bg-red-50 dark:bg-red-900/20 px-4 py-3 text-sm text-red-700 dark:text-red-400">
                    {{ $errorMessage }}
                </div>
            @endif

            {{-- Role select --}}
            <flux:field>
                <flux:label>{{ __('clearance::ui.common.role') }}</flux:label>
                <flux:select wire:model="selectedRoleId">
                    <option value="">{{ __('clearance::ui.user_clearance.assign.pick_role') }}</option>
                    @foreach($availableRoles as $role)
                        <option value="{{ $role->id }}">{{ $role->name }} ({{ $role->guard_name }})</option>
                    @endforeach
                </flux:select>
            </flux:field>

            {{-- Context instance checkboxes (contextual scope only) --}}
            @if($scope === 'contextual' && count($contextInstances) > 0)
                <flux:field>
                    <flux:label>{{ __('clearance::ui.user_clearance.assign.pick_context_instances') }}</flux:label>
                    <div class="grid grid-cols-2 gap-2 mt-1">
                        @foreach($contextInstances as $instance)
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox"
                                       wire:model="selectedContextIds"
                                       value="{{ $instance->getKey() }}"
                                       class="rounded border-gray-300 dark:border-gray-600 text-black-500 cursor-pointer">
                                <span class="text-sm">{{ $instance->name ?? '#' . $instance->getKey() }}</span>
                            </label>
                        @endforeach
                    </div>
                </flux:field>
            @endif

            <div class="flex items-center justify-between pt-4 mt-2 border-t border-gray-200 dark:border-gray-700">
                <flux:button x-on:click="$flux.modal('{{ $modalName }}').close()" variant="ghost">
                    {{ __('clearance::ui.common.cancel') }}
                </flux:button>
                <flux:button wire:click="save" variant="primary">
                    {{ __('clearance::ui.user_clearance.assign.save') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
