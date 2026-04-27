<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionOverride extends Model
{
    public const TYPE_FORCED_ON  = 'forced_on';
    public const TYPE_FORCED_OFF = 'forced_off';

    protected $table = 'clearance_role_permission_overrides';

    protected $fillable = ['parent_role_id', 'child_role_id', 'permission_id', 'type'];

    /**
     * The parent role that owns the permission being overridden.
     */
    public function parentRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'parent_role_id');
    }

    /**
     * The child role that has this override applied.
     */
    public function childRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'child_role_id');
    }

    /**
     * The permission being overridden.
     */
    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class);
    }

    /**
     * Returns true if this override forces the permission on.
     */
    public function isForcedOn(): bool
    {
        return $this->type === self::TYPE_FORCED_ON;
    }

    /**
     * Returns true if this override forces the permission off.
     */
    public function isForcedOff(): bool
    {
        return $this->type === self::TYPE_FORCED_OFF;
    }
}
