<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Support;

/**
 * Names of the Spatie tables and pivot columns, read from the host's `permission` configuration with Spatie's defaults.
 *
 * Every raw query on a Spatie pivot table takes its names from here, so a host that renames a pivot column
 * (for example `role_pivot_key`) keeps working.
 */
final class SpatieSchema
{
    /**
     * Name of the pivot column that holds the role key (`role_id` by default).
     */
    public static function rolePivotKey(): string
    {
        return (string) (config('permission.column_names.role_pivot_key') ?: 'role_id');
    }

    /**
     * Name of the pivot column that holds the permission key (`permission_id` by default).
     */
    public static function permissionPivotKey(): string
    {
        return (string) (config('permission.column_names.permission_pivot_key') ?: 'permission_id');
    }

    /**
     * Name of the pivot column that holds the model key (`model_id` by default).
     */
    public static function modelMorphKey(): string
    {
        return (string) (config('permission.column_names.model_morph_key') ?: 'model_id');
    }

    /**
     * Name of a Spatie table (`roles`, `permissions`, `model_has_roles`, ...), falling back to the key itself.
     */
    public static function table(string $key): string
    {
        return (string) (config("permission.table_names.{$key}") ?: $key);
    }
}
