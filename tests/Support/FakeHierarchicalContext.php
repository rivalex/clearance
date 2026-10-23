<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Rivalex\Clearance\Contracts\ClearanceHierarchicalContext;

class FakeHierarchicalContext extends Model implements ClearanceHierarchicalContext
{
    protected $table = 'fake_hierarchical_contexts';

    protected $guarded = [];

    public $timestamps = false;

    /**
     * Returns the parent context resolved by parent_id, or null for a root.
     */
    public function clearanceParentContext(): ?Model
    {
        $parentId = $this->getAttribute('parent_id');

        return $parentId === null ? null : static::query()->find($parentId);
    }
}
