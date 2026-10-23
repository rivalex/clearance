<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Concerns;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Rivalex\Clearance\Models\Permission;

/**
 * Provides permission-group accessor methods and grouping utilities.
 *
 * Add this trait to any model extending Spatie's Permission model to enable
 * Clearance's permission-group features (grouped view, colors, ability sorting).
 *
 * @property-read string $permission_group  Extracted group name from permission name
 * @property-read string $group_string      Human-readable version of the permission group
 * @property-read array  $abilities         Array of abilities with their respective colors
 */
trait HasPermissionGroups
{
    /**
     * Merges permission_group and group_string into $appends during model initialization.
     * Uses Eloquent's initialize{TraitName}() convention to avoid property-default conflicts
     * with Illuminate\Database\Eloquent\Model::$appends.
     */
    public function initializeHasPermissionGroups(): void
    {
        $this->appends = array_unique(array_merge($this->appends, ['permission_group', 'group_string']));
    }

    /**
     * Extracts the group portion from the permission name using the configured separator.
     */
    public function getPermissionGroupAttribute(): string
    {
        if (! isset($this->name) || empty($this->name)) {
            return $this->attributes['permission_group'] ?? '';
        }

        $separator = config('clearance.naming_separator', '-');
        $pos = strpos($this->name, $separator);

        return $pos === false ? $this->name : substr($this->name, 0, $pos);
    }

    /**
     * Returns the headline-formatted group name.
     */
    public function getGroupStringAttribute(): string
    {
        return Str::headline($this->permission_group ?? '');
    }

    /**
     * Returns the permissions whose name starts exactly with the group prefix and the separator.
     *
     * The LIKE query is only a coarse pre-filter (`%`, `_` act as wildcards and SQLite has no default
     * escape character); the `str_starts_with()` filter is the guarantee, so group `audit_log` never
     * matches `audit-log-read`.
     *
     * @return Collection<int, Permission>
     */
    public static function forGroup(string $group, ?string $guard = null): Collection
    {
        $prefix = $group.config('clearance.naming_separator', '-');

        $query = static::where('name', 'like', $prefix.'%');

        if ($guard !== null) {
            $query->where('guard_name', $guard);
        }

        return $query->get()
            ->filter(fn ($permission) => str_starts_with((string) $permission->name, $prefix))
            ->values();
    }

    /**
     * Returns all permissions for a group, structured with sorted abilities and colors.
     *
     * A permission whose name has no separator (for example `edit articles`) is returned as a one-ability
     * group flagged with `ungrouped`.
     *
     * @return array<string, mixed>
     */
    public static function abilities(string $group, string $guard = 'web'): array
    {
        $separator = config('clearance.naming_separator', '-');
        $permissions = static::forGroup($group, $guard);

        if ($permissions->isEmpty()) {
            $single = static::where('name', $group)->where('guard_name', $guard)->first();

            if ($single === null) {
                return [];
            }

            return [
                'abilities' => [[
                    'color' => static::colorForAbility($group),
                    'label' => Str::headline($group),
                    'name' => $group,
                ]],
                'created_at' => $single->created_at,
                'guard_name' => $single->guard_name,
                'labels' => [
                    'group' => Str::headline($group),
                    'guard' => Str::headline($guard),
                ],
                'permission_group' => $group,
                'ungrouped' => true,
                'updated_at' => $single->updated_at,
            ];
        }

        $first = $permissions->first();

        $abilities = $permissions->map(function ($permission) use ($group, $separator) {
            $ability = str_replace("{$group}{$separator}", '', $permission->name);

            return [
                'color' => static::colorForAbility($ability),
                'label' => Str::headline($ability),
                'name' => $ability,
            ];
        });

        $sortedNames = static::sortAbilities($abilities->pluck('name')->toArray());

        $sortedAbilities = collect($sortedNames)->map(fn ($name) => $abilities->firstWhere('name', $name))
            ->values()
            ->toArray();

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
     * Sorts abilities: create/read/update/delete first (CRUD order), then remaining alphabetically.
     *
     * @param  array<string>  $abilities
     * @return array<string>
     */
    public static function sortAbilities(array $abilities): array
    {
        $order = ['create', 'read', 'update', 'delete'];

        usort($abilities, function (string $a, string $b) use ($order): int {
            $posA = array_search($a, $order, true);
            $posB = array_search($b, $order, true);

            if ($posA !== false && $posB !== false) {
                return $posA <=> $posB;
            }

            if ($posA !== false) {
                return -1;
            }

            if ($posB !== false) {
                return 1;
            }

            return strcasecmp($a, $b);
        });

        return $abilities;
    }

    /**
     * Returns the Flux color token for a given ability name.
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
     * Returns the grouped abilities for this permission's group and guard.
     *
     * @return array<string, mixed>
     */
    public function getAbilitiesAttribute(): array
    {
        if (! isset($this->name) || empty($this->name)) {
            return [];
        }

        return static::abilities($this->permission_group, $this->guard_name);
    }
}
