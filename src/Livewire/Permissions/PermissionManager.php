<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Livewire\Permissions;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;
use Rivalex\Clearance\Clearance;
use Rivalex\Clearance\Models\Permission;

/**
 * List screen for permissions, grouped by prefix with search and pagination (V6, V8).
 * Create/edit/delete handled by child modal components (NewPermission, EditPermission, DeletePermission).
 */
class PermissionManager extends Component
{
	use WithPagination;
	
	public string $search = '';
	
	/**
	 * No-op mount kept for test/Livewire lifecycle compatibility.
	 */
	public function mount(): void
	{
	}
	
	/**
	 * Reset pagination when search changes.
	 */
	public function updatingSearch(): void
	{
		$this->search = mb_substr($this->search, 0, 100);
		$this->resetPage();
	}
	
	/**
	 * Re-render after a child modal saves or deletes.
	 */
	#[On('permission-saved')]
	#[On('permission-deleted')]
	public function refresh(): void
	{
		$this->resetPage();
	}
	
	/**
	 * Fetches and groups permissions by prefix and guard.
	 */
	protected function getGroupedPermissions(): LengthAwarePaginator
	{
		$sep = config('clearance.naming_separator', '-');
		if (! preg_match('/^[\-_]$/', (string) $sep)) {
			$sep = '-';
		}
		$driver = config('database.default');
		$connection = config("database.connections.{$driver}.driver", $driver);

		$groupSql = match ($connection) {
			'mysql', 'mariadb' => "SUBSTRING_INDEX(name, '{$sep}', 1)",
			'sqlite'           => "SUBSTR(name, 1, INSTR(name, '{$sep}') - 1)",
			'pgsql'            => "SPLIT_PART(name, '{$sep}', 1)",
			default            => "CASE WHEN name LIKE '%{$sep}%' THEN SUBSTR(name, 1, INSTR(name, '{$sep}') - 1) ELSE name END",
		};

		// If SQLite doesn't find the separator, INSTR returns 0, and SUBSTR(name, 1, -1) might fail or return empty.
		// Better SQLite extraction:
		if ($connection === 'sqlite') {
			$groupSql = "CASE WHEN INSTR(name, '{$sep}') > 0 THEN SUBSTR(name, 1, INSTR(name, '{$sep}') - 1) ELSE name END";
		}

		$query = Permission::query()
			->selectRaw("{$groupSql} as permission_group, guard_name")
			->when(!empty($this->search), function ($q) {
				$q->where(function ($sub) {
					$sub->whereLike('name', "%{$this->search}%")
						->orWhereLike('guard_name', "%{$this->search}%");
				});
			})
			->groupBy('permission_group', 'guard_name')
			->orderBy('permission_group');

		$paginated = $query->paginate(10);

		$paginated->getCollection()->transform(function ($item) use ($sep) {
			$group = $item->permission_group;
			$guard = $item->guard_name;
			$key = $group . $sep . $guard;
			return [
				'group'    => $group,
				'guard'    => $guard,
				'groupKey' => md5($key),
			];
		});

		return $paginated;
	}
	
	public function render(): View
	{
		abort_unless(app(Clearance::class)->canAccess(), 403);

		return view('clearance::livewire.permissions.permission-manager', [
			'groupedPermissions' => $this->getGroupedPermissions(),
		]);
	}
}
