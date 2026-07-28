<div>
    <flux:modal.trigger name="{{ $modalName }}">
        <flux:button variant="ghost" size="xs" color="red" icon="trash">{{ __('clearance::ui.common.delete') }}</flux:button>
    </flux:modal.trigger>

    <flux:modal name="{{ $modalName }}" class="md:w-[36rem]">
        <x-clearance::dark-scope class="space-y-4">
            @if($role !== null)
                @if($isLocked)
                    {{-- Branch 1: locked role --}}
                    <div class="pb-4 border-b border-gray-200 dark:border-gray-700">
                        <flux:heading size="lg">{{ __('clearance::ui.roles.delete.cannot_delete') }}</flux:heading>
                        <flux:text class="text-gray-500 dark:text-gray-400">
                            {{ __('clearance::ui.roles.delete.locked_cannot_delete') }}
                        </flux:text>
                    </div>
                    <div class="flex justify-end pt-4 mt-2 border-t border-gray-200 dark:border-gray-700">
                        <flux:button x-on:click="$flux.modal('{{ $modalName }}').close()" variant="ghost">{{ __('clearance::ui.roles.delete.close') }}</flux:button>
                    </div>

                @elseif($affectedGlobalCount > 0 || $affectedContextualCount > 0)
                    {{-- Branch 2: role has assigned users --}}
                    <div class="pb-4 border-b border-gray-200 dark:border-gray-700">
                        <flux:heading size="lg">{{ __('clearance::ui.roles.delete.confirm_title') }}</flux:heading>
                        <flux:text class="text-gray-500 dark:text-gray-400">
                            {{ __('clearance::ui.roles.delete.affected_users', ['global' => $affectedGlobalCount, 'contextual' => $affectedContextualCount]) }}
                        </flux:text>
                    </div>

                    @if($errorMessage)
                        <div class="rounded-md bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 px-4 py-3 text-sm text-red-700 dark:text-red-400">
                            {{ $errorMessage }}
                        </div>
                    @endif

                    <div class="space-y-2">
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            {{ __('clearance::ui.roles.delete.action_label') }}
                        </p>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" wire:model.live="action" value="detach">
                            <span class="text-sm">{{ __('clearance::ui.roles.delete.action_detach') }}</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" wire:model.live="action" value="reassign">
                            <span class="text-sm">{{ __('clearance::ui.roles.delete.action_reassign') }}</span>
                        </label>
                    </div>

                    @if($action === 'reassign')
                        <div class="space-y-1">
                            <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                {{ __('clearance::ui.roles.delete.reassign_to') }}
                            </p>
                            @if($otherRoles->isEmpty())
                                <p class="text-sm text-gray-400">{{ __('clearance::ui.roles.delete.no_other_roles') }}</p>
                            @else
                                <flux:select wire:model="targetRoleId">
                                    <flux:select.option value="">{{ __('clearance::ui.common.select') }}</flux:select.option>
                                    @foreach($otherRoles as $r)
                                        <flux:select.option value="{{ $r->id }}">{{ $r->name }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            @endif
                        </div>
                    @endif

                    <flux:text class="text-gray-500 dark:text-gray-400 text-sm">
                        {{ __('clearance::ui.roles.delete.confirm_desc', ['confirm' => 'DELETE ' . $role->name]) }}
                    </flux:text>
                    <flux:input wire:model="confirmText" placeholder="DELETE {{ $role->name }}" class="font-mono" />
                    <div class="flex items-center justify-between pt-4 mt-2 border-t border-gray-200 dark:border-gray-700">
                        <flux:button x-on:click="$flux.modal('{{ $modalName }}').close()" variant="ghost">{{ __('clearance::ui.common.cancel') }}</flux:button>
                        <flux:button wire:click="confirmDelete" variant="danger">{{ __('clearance::ui.roles.delete.confirm_delete') }}</flux:button>
                    </div>

                @else
                    {{-- Branch 3: no assigned users, standard typed-confirm --}}
                    <div class="pb-4 border-b border-gray-200 dark:border-gray-700">
                        <flux:heading size="lg">{{ __('clearance::ui.roles.delete.confirm_title') }}</flux:heading>
                        <flux:text class="text-gray-500 dark:text-gray-400">
                            {{ __('clearance::ui.roles.delete.confirm_desc', ['confirm' => 'DELETE ' . $role->name]) }}
                        </flux:text>
                    </div>
                    <flux:input wire:model="confirmText" placeholder="DELETE {{ $role->name }}" class="font-mono" />
                    <div class="flex items-center justify-between pt-4 mt-2 border-t border-gray-200 dark:border-gray-700">
                        <flux:button x-on:click="$flux.modal('{{ $modalName }}').close()" variant="ghost">{{ __('clearance::ui.common.cancel') }}</flux:button>
                        <flux:button wire:click="confirmDelete" variant="danger">{{ __('clearance::ui.roles.delete.confirm_delete') }}</flux:button>
                    </div>
                @endif
            @endif
        </x-clearance::dark-scope>
    </flux:modal>
</div>
