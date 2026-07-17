<div class="space-y-4 clearance"
     x-data="permissionForm($wire.entangle('customAbilities'), @js($existingCustomAbilities), @js($standardAbilities), $wire.entangle('crudAbilities'), @js($availableGuards))">
	<div class="pb-4 border-b border-gray-200 dark:border-gray-700">
		<flux:heading size="xl">
			{!! $editingPrefix !== ''
				? __('clearance::ui.permissions.form.edit_title', ['group' => '<b>' . e($editingPrefix) . '</b>'])
				: __('clearance::ui.permissions.form.new_title')
			!!}
		</flux:heading>
		<flux:text class="text-gray-500 dark:text-gray-400">
			{{ $editingPrefix !== '' ? __('clearance::ui.permissions.form.edit_desc') : __('clearance::ui.permissions.form.new_desc') }}
		</flux:text>
	</div>
	
	@if($errorMessage)
		<div class="rounded-md bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 px-4 py-3 text-sm text-red-700 dark:text-red-400">
			{{ $errorMessage }}
		</div>
	@endif
	
	{{-- Prefix + Guard --}}
	<div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
		<div>
			<flux:input
					wire:model="prefix"
					label="{{ __('clearance::ui.permissions.form.prefix') }}"
					placeholder="{{ __('clearance::ui.permissions.form.prefix_placeholder') }}"
					class="font-mono"
					:readonly="$editingPrefix !== ''"
					:disabled="$editingPrefix !== ''"
					:invalid="$errors->has('prefix')"
			/>
		</div>
		
		<div x-cloak x-show="$wire.editingPrefix === ''">
			<flux:select wire:model="guardName" label="{{ __('clearance::ui.common.guard') }}"
			             :invalid="$errors->has('guardName')">
				<template x-for="guard in availableGuards" :key="guard">
					<option :value="guard" x-text="guard"></option>
				</template>
			</flux:select>
			<flux:error name="guardName"/>
		</div>
	</div>
	
	<div class="flex flex-col gap-2">
		<div class="flex items-center justify-between gap-2">
			<flux:separator class="flex-1 tracking-widest"
			                text="{{ __('clearance::ui.permissions.form.standard_abilities') }}"/>
			<div class="flex gap-2">
				<flux:button variant="ghost" size="xs"
				             @click="selectAll()">{{ __('clearance::ui.permissions.form.select_all') }}</flux:button>
				<flux:button variant="ghost" size="xs"
				             @click="selectNone()">{{ __('clearance::ui.permissions.form.select_none') }}</flux:button>
			</div>
		</div>
		
		<div class="grid grid-cols-2 md:grid-cols-4 gap-4 w-full">
			<template x-for="ability in standardAbilities" :key="ability">
				<div class="w-full place-items-center">
					<label class="crud-pills w-full flex items-center justify-center gap-2 py-1 rounded-lg border border-gray-200 dark:border-gray-700 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-black-500 has-[:focus-visible]:ring-offset-2 dark:has-[:focus-visible]:ring-offset-gray-900"
					       :class="ability === 'delete' ? 'red' : 'green'"
					       :data-checked="crudAbilities.includes(ability)">
						<input type="checkbox" :value="ability" x-model="crudAbilities" class="sr-only">
						<div class="flex gap-2 items-center">
							<span x-html="getIcon(ability)"></span>
							<div class="h-4 w-px bg-gray-200 dark:border-gray-700/50"></div>
							<p class="pe-1 text-sm font-medium" x-text="headline(ability)"></p>
							<svg x-show="crudAbilities.includes(ability)" x-cloak class="w-3 h-3 shrink-0" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd"/></svg>
						</div>
					</label>
				</div>
			</template>
		</div>
	</div>
	
	<div class="flex flex-col gap-2 font-light">
		<flux:separator text="{{ __('clearance::ui.permissions.form.custom_abilities') }}"/>
		
		<div x-cloak x-show="abilities.length > 0" class="flex flex-wrap gap-2">
			<template x-for="(ability, index) in abilities"
			          :key="'ability-' + ability">
				<flux:badge size="sm" color="zinc" class="flex items-center gap-1 select-none">
					<span x-text="ability"></span>
					<flux:badge.close @click.prevent.stop="removeAbility(index)"/>
				</flux:badge>
			</template>
		</div>
		
		<datalist id="clearance-ability-suggestions">
			<template x-for="suggestion in suggestions" :key="'suggestion-' + suggestion">
				<option :value="suggestion"></option>
			</template>
		</datalist>
		<div class="flex items-center gap-2"
		     @keydown.enter.prevent="addAbility">
			<flux:input
					x-model="newAbility"
					list="clearance-ability-suggestions"
					placeholder="{{ __('clearance::ui.permissions.form.custom_ability_placeholder') }}"
					class="font-mono w-56"
			/>
			<flux:button @click.prevent="addAbility" size="sm" variant="ghost" icon="plus">
				{{ __('clearance::ui.permissions.form.add') }}
			</flux:button>
		</div>
		
		<div class="flex items-center justify-between pt-4 mt-2 border-t border-gray-200 dark:border-gray-700">
			<flux:button x-on:click="$flux.modal('{{ $modalName }}').close()" variant="ghost">{{ __('clearance::ui.common.cancel') }}</flux:button>
			<flux:button wire:click="save" variant="primary">
				{{ __('clearance::ui.common.save') }}
			</flux:button>
		</div>
	</div>
</div>

@script
<script>
    Alpine.data('permissionForm', (customAbilities, existingAbilities, standardAbilities, selectedCrud, availableGuards) => {
        return {
            abilities: customAbilities,
            existingAbilities: existingAbilities,
            standardAbilities: standardAbilities,
            crudAbilities: selectedCrud,
            availableGuards: availableGuards,
            newAbility: '',

            get suggestions() {
                return this.existingAbilities.filter((ability) => !this.abilities.includes(ability));
            },

            slugify(text) {
                if (!text) return '';

                return text
                    .toString()
                    .normalize('NFD')
                    .replace(/[\u0300-\u036f]/g, '')
                    .replace(/\s+/g, ' ')
                    .trim()
                    .toLowerCase()
                    .replace(/[^a-z0-9_]/g, '-')
                    .replace(/-+/g, '-')
                    .replace(/^-+/, '')
                    .replace(/-+$/, '');
            },

            addAbility() {
                const val = this.slugify(this.newAbility);

                if (val !== '' && !this.abilities.includes(val) && !this.standardAbilities.includes(val)) {
                    this.abilities = [...this.abilities, val];
                }

                this.newAbility = '';
            },

            removeAbility(index) {
                this.abilities = this.abilities.filter((_, i) => i !== index);
            },

            selectAll() {
                this.crudAbilities = [...this.standardAbilities];
            },

            selectNone() {
                this.crudAbilities = [];
            },

            getIcon(ability) {
                const icons = {
                    create: '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-plus-icon lucide-plus w-4 h-4"><path d="M5 12h14"/><path d="M12 5v14"/></svg>',
                    read: '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-search-check-icon lucide-search-check w-4 h-4"><path d="m8 11 2 2 4-4"/><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>',
                    update: '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-pencil-icon lucide-pencil w-4 h-4"><path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"/><path d="m15 5 4 4"/></svg>',
                    delete: '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-trash2-icon lucide-trash-2 w-4 h-4"><path d="M10 11v6"/><path d="M14 11v6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>'
                };
                return icons[ability] || '';
            },

            headline(text) {
                if (!text) return '';
                return text
                    .replace(/[_-]/g, ' ')
                    .split(' ')
                    .map(word => word.charAt(0).toUpperCase() + word.slice(1))
                    .join(' ');
            }
        }
    });
</script>
@endscript
