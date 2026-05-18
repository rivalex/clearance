<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Concerns;

use Illuminate\Support\Str;

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
    /** @var array<string> */
    protected $appends = ['permission_group', 'group_string'];

    /**
     * Extracts the group portion from the permission name using the configured separator.
     */
    public function getPermissionGroupAttribute(): string
    {
        if (! isset($this->name) || empty($this->name)) {
            return $this->attributes['permission_group'] ?? '';
        }

        $separator = config('clearance.naming_separator', '-');
        $pos       = strpos($this->name, $separator);

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
     * Returns all permissions for a group, structured with sorted abilities and colors.
     *
     * @return array<string, mixed>
     */
    public static function abilities(string $group, string $guard = 'web'): array
    {
        $separator   = config('clearance.naming_separator', '-');
        $permissions = static::where('name', 'like', "{$group}{$separator}%")
            ->where('guard_name', $guard)
            ->get();

        if ($permissions->isEmpty()) {
            return [];
        }

        $first = $permissions->first();

        $abilities = $permissions->map(function ($permission) use ($group, $separator) {
            $ability = str_replace("{$group}{$separator}", '', $permission->name);

            return [
                'color' => static::colorForAbility($ability),
                'label' => Str::headline($ability),
                'name'  => $ability,
            ];
        });

        $sortedNames = static::sortAbilities($abilities->pluck('name')->toArray());

        $sortedAbilities = collect($sortedNames)->map(fn ($name) => $abilities->firstWhere('name', $name))
            ->values()
            ->toArray();

        return [
            'abilities'        => $sortedAbilities,
            'created_at'       => $first->created_at,
            'guard_name'       => $first->guard_name,
            'labels'           => [
                'group' => Str::headline($group),
                'guard' => Str::headline($guard),
            ],
            'permission_group' => $group,
            'updated_at'       => $first->updated_at,
        ];
    }

    /**
     * Sorts abilities: create/read/update/delete first (CRUD order), then remaining alphabetically.
     *
     * @param  array<string> $abilities
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
            'delete'                   => 'red',
            default                    => 'amber',
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
