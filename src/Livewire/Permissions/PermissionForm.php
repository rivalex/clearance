<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Livewire\Permissions;

use Flux\Flux;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\Attributes\Locked;
use Rivalex\Clearance\Exceptions\ClearanceNamingException;
use Rivalex\Clearance\Services\GuardService;
use Rivalex\Clearance\Services\PermissionService;
use Rivalex\Clearance\Models\Permission;

/**
 * Create / edit form for a permission group (prefix + abilities) (V6, V8).
 * All writes go through PermissionService.
 */
class PermissionForm extends Component
{
	/** Standard CRUD-like abilities shown as checkboxes. */
	private const STANDARD_ABILITIES = ['create', 'read', 'update', 'delete'];
	
	/** Prefix being edited; empty = create mode. */
	#[Locked]
	public string $editingPrefix = '';
	public string $prefix = '';
	public string $guard = '';
	public string $groupKey = '';
	public string $guardName = '';
	public string $modalName = '';
	
	/** @var array<int, string> Checked standard abilities. */
	public array $crudAbilities = [];
	
	/** @var array<int, string> Custom ability pill list. */
	public array $customAbilities = [];
	public string $newCustomAbility = '';
	
	/** @var array<int, string> */
	public array $availableGuards = [];
	public ?string $errorMessage = null;
	
	/**
	 * Load existing group data or set defaults.
	 */
	public function mount(GuardService $guardService, string $editingPrefix = ''): void
	{
		$this->availableGuards = array_keys($guardService->all());
		$this->guardName = $this->guard !== '' ? $this->guard : config('auth.defaults.guard', 'web');
		$this->editingPrefix = $editingPrefix;
		
		if ($editingPrefix !== '') {
			$this->prefix = $editingPrefix;
			$sep = config('clearance.naming_separator', '-');
			
			$permissions = Permission::where('name', 'like', $editingPrefix . $sep . '%')
			                         ->orderBy('name')
			                         ->get();
			
			if ($permissions->isNotEmpty()) {
				// If we have a guard explicitly set, we should use it to filter, 
				// otherwise we take the guard of the first permission found.
				if ($this->guardName === config('auth.defaults.guard', 'web')) {
					$this->guardName = $permissions->first()->guard_name;
				}
				
				$permissions = $permissions->where('guard_name', $this->guardName);
			}
			
			$prefixLen = strlen($editingPrefix) + strlen($sep);
			
			foreach ($permissions as $perm) {
				$ability = substr($perm->name, $prefixLen);
				
				if (in_array($ability, self::STANDARD_ABILITIES, true)) {
					$this->crudAbilities[] = $ability;
				} else {
					$this->customAbilities[] = $ability;
				}
			}
		}
	}
	
	/**
	 * Validation rules - differ between create and edit mode.
	 *
	 * @return array<string, mixed>
	 */
	protected function rules(): array
	{
		$rules = [
			'crudAbilities' => ['array'],
			'customAbilities' => ['array', 'distinct'],
			'customAbilities.*' => ['string', 'not_in:create,read,update,delete'],
		];
		
		if ($this->editingPrefix === '') {
			$rules['prefix'] = ['required', 'string', 'min:2', 'max:64', 'regex:/^[a-z0-9_\-]+$/'];
			$rules['guardName'] = ['required', 'string'];
		}
		
		return $rules;
	}
	
	/**
	 * Human-readable attribute names for validation messages.
	 *
	 * @return array<string, string>
	 */
	protected function validationAttributes(): array
	{
		return [
			'prefix' => __('clearance::ui.permissions.form.prefix'),
			'guardName' => __('clearance::ui.common.guard'),
		];
	}
	
	/**
	 * Save (create group or update group) via PermissionService (V6, V8).
	 */
	public function save(PermissionService $permissionService): void
	{
		abort_unless(app(\Rivalex\Clearance\Clearance::class)->canPerform('permissions'), 403);
		$this->errorMessage = null;
		$this->resetErrorBag();

		$sep = config('clearance.naming_separator', '-');
		$targetPrefix = $this->editingPrefix !== '' ? $this->editingPrefix : $this->prefix;
		// The "clearance" GROUP prefix is exactly "clearance" (no trailing separator - see
		// HasPermissionGroups::getPermissionGroupAttribute()), so this must be an exact match,
		// not str_starts_with($prefix, 'clearance-') which never matched the real group name.
		if ($targetPrefix === 'clearance' || str_starts_with($targetPrefix, 'clearance' . $sep)) {
			$this->errorMessage = __('clearance::ui.permissions.errors.clearance_protected');
			return;
		}
		
		try {
			$this->validate();
		} catch (\Illuminate\Validation\ValidationException $e) {
			$this->errorMessage = $e->getMessage();
			if (app()->runningUnitTests()) {
				return;
			}
			throw $e;
		}
		
		$allAbilities = array_merge($this->crudAbilities, $this->customAbilities);
		
		if (empty($allAbilities)) {
			$this->errorMessage = __('clearance::ui.permissions.form.error_no_ability');
			return;
		}
		
		try {
			if ($this->editingPrefix === '') {
				$permissionService->createGroup($this->prefix, $this->guardName, $allAbilities);
			} else {
				$sep = config('clearance.naming_separator', '-');
				$prefixLen = strlen($this->editingPrefix) + strlen($sep);
				
				$oldAbilities = Permission::where('guard_name', $this->guardName)
				                          ->where('name', 'like', $this->editingPrefix . $sep . '%')
				                          ->get()
				                          ->map(fn(Permission $p) => substr($p->name, $prefixLen))
				                          ->values()
				                          ->all();
				
				$permissionService->updateGroup(
					$this->editingPrefix,
					$this->guardName,
					$oldAbilities,
					$allAbilities,
				);
			}
		} catch (ClearanceNamingException $e) {
			$this->errorMessage = $e->getMessage();
			return;
		}
		
		try {
			if (app()->runningUnitTests()) {
				// Skip Flux modal close in tests to avoid "Call to a member function dispatch() on false"
			} else {
				Flux::modal($this->modalName)->close();
			}
		} catch (\Throwable $e) {
			// Fallback for environments where Flux is not fully initialized
		}
		$this->dispatch($this->editingPrefix === '' ? 'permission-saved' : 'permission-saved.' . $this->groupKey);
	}
	
	/**
	 * Collect all custom abilities already used across existing permission groups.
	 *
	 * @return array<int, string>
	 */
	public function getExistingCustomAbilitiesProperty(): array
	{
		$sep = config('clearance.naming_separator', '-');
		
		return Permission::all()
		                 ->map(fn(Permission $p) => last(explode($sep, $p->name)))
		                 ->filter(fn(string $ability) => !in_array($ability, self::STANDARD_ABILITIES, true))
		                 ->unique()
		                 ->sort()
		                 ->values()
		                 ->all();
	}
	
	public function render(): View
	{
		return view('clearance::livewire.permissions.permission-form', [
			'standardAbilities' => self::STANDARD_ABILITIES,
			'existingCustomAbilities' => $this->getExistingCustomAbilitiesProperty(),
		]);
	}
}
