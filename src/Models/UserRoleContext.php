<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Role;

class UserRoleContext extends Model
{
    protected $table = 'clearance_user_role_contexts';

    protected $fillable = ['user_id', 'role_id', 'context_type', 'context_id'];

    /**
     * The role assigned in this context.
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
