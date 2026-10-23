<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;

/**
 * @property int $id
 * @property int $user_id
 * @property int $role_id
 * @property string $context_type
 * @property int $context_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class UserRoleContext extends Model
{
    protected $table = 'clr_role_ctx';

    protected $fillable = ['user_id', 'role_id', 'context_type', 'context_id'];

    /**
     * The role assigned in this context.
     *
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
