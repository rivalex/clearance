<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Role;

class RoleHierarchy extends Model
{
    protected $table = 'clearance_role_hierarchy';

    protected $fillable = ['parent_role_id', 'child_role_id'];

    /**
     * The parent role in this hierarchy relationship.
     */
    public function parentRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'parent_role_id');
    }

    /**
     * The child role in this hierarchy relationship.
     */
    public function childRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'child_role_id');
    }
}
