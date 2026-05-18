<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Livewire\Permissions;

use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Rivalex\Clearance\Models\Permission;

/**
 * Represents a single group-guard row in the PermissionManager.
 * Handles display and actions for all abilities within that group.
 */
class PermissionGroupRow extends Component
{
	public string $group;
	public string $guard;
	public ?string $groupKey = null;
	public array $groupData = [];
	
	public string $prefix = '';
	public string $sep = '';
	public array $abilities = [];
	public array $labels = [];
	
	public function mount(): void
	{
		$this->buildDefault();
	}
	
	protected function buildDefault(): void
	{
		$this->groupKey = $this->groupKey ?: md5($this->group . '|' . $this->guard);
		$this->prefix = $this->prefix ?: $this->group;
		$this->sep = $this->sep ?: config('clearance.naming_separator', '-');
		
		if (empty($this->groupData)) {
			$this->refreshGroupData();
		}
	}
	
	protected function refreshGroupData(): void
	{
		$this->groupData = Permission::abilities(group: $this->group, guard: $this->guard);
		$this->abilities = $this->groupData['abilities'];
		$this->labels = $this->groupData['labels'];
	}
	
	public function placeholder(): View
	{
		return view('clearance::livewire.permissions.placeholder');
	}
	
	/**
	 * Reload group data when a permission is saved (handles edit from child modal).
	 */
	public function reloadGroup(): void
	{
		$this->refreshGroupData();
	}
	
	public function getListeners(): array
	{
		return [
			"permission-saved.{$this->groupKey}" => 'reloadGroup',
		];
	}
	
	public function render(): View
	{
		return view('clearance::livewire.permissions.permission-group-row');
	}
}
