<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Permission\Models\Role;

class RoleMeta extends Model
{
    public const SCOPE_GLOBAL = 'global';

    public const SCOPE_CONTEXTUAL = 'contextual';

    protected $table = 'clr_role_meta';

    protected $fillable = ['role_id', 'is_locked', 'scope', 'context_types', 'parent_role_id'];

    protected $attributes = [
        'is_locked' => false,
        'scope' => self::SCOPE_GLOBAL,
    ];

    protected $casts = [
        'is_locked' => 'boolean',
        'context_types' => 'array',
    ];

    /**
     * The Spatie role this metadata belongs to.
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * The ceiling (parent) role — null when this role has no ceiling.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'parent_role_id');
    }

    /**
     * RoleMeta rows whose parent_role_id equals this role's role_id.
     * Local key is role_id (not id) because parent_role_id stores the Spatie role PK.
     */
    public function children(): HasMany
    {
        return $this->hasMany(RoleMeta::class, 'parent_role_id', 'role_id');
    }

    /**
     * True when this role is a child of a ceiling role.
     */
    public function isChild(): bool
    {
        return $this->parent_role_id !== null;
    }

    /**
     * True when at least one other role declares this role as its ceiling.
     */
    public function isParent(): bool
    {
        return $this->children()->exists();
    }

    /** True when role is globally scoped (no context binding). */
    public function isGlobal(): bool
    {
        return $this->scope === self::SCOPE_GLOBAL;
    }

    /** True when role is contextually scoped (context_types binding required). */
    public function isContextual(): bool
    {
        return $this->scope === self::SCOPE_CONTEXTUAL;
    }

    /** Returns true when this contextual role accepts the given model FQCN. */
    public function acceptsContext(string $fqcn): bool
    {
        return $this->isContextual() && in_array($fqcn, $this->context_types ?? [], true);
    }
}
