<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Role;

class RoleMeta extends Model
{
    protected $table = 'clearance_role_meta';

    protected $fillable = ['role_id', 'is_system', 'is_protected'];

    protected $attributes = [
        'is_system'    => false,
        'is_protected' => false,
    ];

    protected $casts = [
        'is_system'    => 'boolean',
        'is_protected' => 'boolean',
    ];

    /**
     * The Spatie role this metadata belongs to.
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
