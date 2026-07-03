<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Rivalex\Clearance\Models\Permission;
use Spatie\Permission\Models\Role;

class UserContextPermissionOverride extends Model
{
    public const TYPE_FORCED_ON = 'forced_on';

    public const TYPE_FORCED_OFF = 'forced_off';

    protected $table = 'clr_ctx_overrides';

    protected $fillable = ['user_id', 'role_id', 'permission_id', 'context_type', 'context_id', 'type'];

    /**
     * The role this override belongs to.
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
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

    /**
     * Scope to a specific user-role-context tuple.
     *
     * @param  int|string  $userId
     * @param  int|string  $contextId
     */
    public static function forSubject(
        mixed $userId,
        int $roleId,
        string $contextType,
        int|string $contextId,
    ): Builder {
        return static::query()
            ->where('user_id', $userId)
            ->where('role_id', $roleId)
            ->where('context_type', $contextType)
            ->where('context_id', $contextId);
    }
}
