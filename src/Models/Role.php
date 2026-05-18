<?php

namespace App\Models\Permissions;

use App\Enums\System\RoleGuard;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Class Role
 *
 * This class extends the \Spatie\Permission\Models\Role class and includes additional functionality for searching and retrieving the count of associated users.
 *
 * @package App\Models\Permissions
 *
 * @property string    $name
 * @property string    $guard_name
 * @property string    $group
 * @property integer   $users_count
 * @property RoleGuard $guard
 *
 */
class Role extends SpatieRole
{
	use LogsActivity;

	public function getActivitylogOptions(): LogOptions
	{
		return LogOptions::defaults()
		                 ->useLogName('Role Manager')
		                 ->logAll()
		                 ->logOnlyDirty()
		                 ->setDescriptionForEvent(fn(string $eventName) => "Role has been {$eventName}")
		                 ->logExcept(['updated_at'])
		                 ->dontSubmitEmptyLogs();
	}

	public function getGuardAttribute(): RoleGuard
	{
		return RoleGuard::from($this->guard_name);
	}

	public function getUsersCountAttribute(): int
	{
		return $this->users()->count();
	}

	public function getGuardGroups(): array
	{
		return DB::table('permissions')
		         ->select(DB::raw("DISTINCT LEFT (name, POSITION( '-' IN name )-1 ) AS 'group'"))
		         ->where('guard_name', '=', $this->guard_name)
		         ->orderBy('group')
		         ->pluck('group')->toArray();
	}

	public function getGroupAbilities(string $group): array
	{
		$groupAbilities = DB::table('permissions')
		                    ->select('*')
		                    ->where('guard_name', '=', $this->guard_name)
		                    ->where('name', 'like', $group . '-%')
		                    ->get();

		$abilitiesArray = [];
		foreach ($groupAbilities as $ability) {
			$abilitiesArray[$ability->id] = [
				'id' => $ability->id,
				'name' => $ability->name,
				'ability' => substr($ability->name, strpos($ability->name, '-') + 1),
				'guard_name' => $ability->guard_name
			];
		}

		$basePermissions = ['create', 'read', 'update', 'delete'];

		$core = array_filter($abilitiesArray, function ($ability) use ($basePermissions) {
			return in_array($ability['ability'], $basePermissions, true);
		});

		usort($core, function ($a, $b) use ($basePermissions) {
			$posA = array_search($a['ability'], $basePermissions);
			$posB = array_search($b['ability'], $basePermissions);
			return $posA - $posB;
		});

		$extra = array_filter($abilitiesArray, function ($ability) use ($basePermissions) {
			return !in_array($ability['ability'], $basePermissions, true);
		});

		usort($extra, fn($a, $b) => $a['ability'] <=> $b['ability']);

		return array_merge($core, $extra);

	}
}
