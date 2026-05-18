<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Models;

use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission as SpatiePermission;

/**
 * ## Permission Model Class
 *
 * This class extends the Spatie Permission model to add additional functionality:
 * - Permission group determination from permission name
 *
 * The class is used to manage system permissions and their associated guards.
 * Permissions follow a naming convention of "group-ability" (e.g., "users-create").
 *
 * @property-read string $permission_group Extracted group name from permission name
 * @property-read string $group_string Human-readable version of the permission group
 * @property-read array $abilities Array of abilities with their respective colors
 */
class Permission extends SpatiePermission
{
	protected $appends = ['permission_group', 'group_string'];
	
    public function getPermissionGroupAttribute(): string
    {
        if (!isset($this->name) || empty($this->name)) {
            return $this->attributes['permission_group'] ?? '';
        }

        $separator = config('clearance.naming_separator', '-');
        $pos = strpos($this->name, $separator);
        return $pos === false ? $this->name : substr($this->name, 0, $pos);
    }

	public function getGroupStringAttribute(): string
	{
		return Str::headline($this->permission_group ?? '');
	}

    /**
     * Restituisce tutte le abilità di un determinato gruppo.
     * Restituisce un array strutturato con nome del gruppo, abilità (ordinate) ognuna con il proprio colore.
     */
    public static function abilities(string $group, string $guard = 'web'): array
    {
        $separator = config('clearance.naming_separator', '-');
        $permissions = self::where('name', 'like', "{$group}{$separator}%")
            ->where('guard_name', $guard)
            ->get();

        if ($permissions->isEmpty()) {
            return [];
        }

        $first = $permissions->first();

        $abilities = $permissions->map(function ($permission) use ($group, $separator) {
            $ability = str_replace("{$group}{$separator}", '', $permission->name);
            return [
                'color' => self::colorForAbility($ability),
                'label' => Str::headline($ability),
                'name' => $ability,
            ];
        });

        $sortedNames = self::sortAbilities($abilities->pluck('name')->toArray());

        $sortedAbilities = collect($sortedNames)->map(function ($name) use ($abilities) {
            return $abilities->firstWhere('name', $name);
        })->values()->toArray();

        return [
            'abilities' => $sortedAbilities,
            'created_at' => $first->created_at,
            'guard_name' => $first->guard_name,
            'labels' => [
                'group' => Str::headline($group),
                'guard' => Str::headline($guard),
            ],
            'permission_group' => $group,
            'updated_at' => $first->updated_at,
        ];
    }

    /**
     * Ordina le abilità rispetto al CRUD e le successive (custom) in ordine alfabetico.
     */
    public static function sortAbilities(array $abilities): array
    {
	    usort($abilities, function ($a, $b) {
		    $order = ['create', 'read', 'update', 'delete'];
		    $posA = array_search($a, $order);
            $posB = array_search($b, $order);

            if ($posA !== false && $posB !== false) {
                return $posA <=> $posB;
            }

            if ($posA !== false) return -1;
            if ($posB !== false) return 1;

            return strcasecmp($a, $b);
        });

        return $abilities;
    }

    /**
     * Restituisce il colore per le abilità.
     */
    public static function colorForAbility(string $ability): string
    {
        return match ($ability) {
            'create', 'read', 'update' => 'green',
            'delete' => 'red',
            default => 'amber',
        };
    }

    /**
     * Attribute per lo specifico gruppo/guardia.
     */
    public function getAbilitiesAttribute(): array
    {
        if (!isset($this->name) || empty($this->name)) {
            return [];
        }
        return self::abilities($this->permission_group, $this->guard_name);
    }
	
}
