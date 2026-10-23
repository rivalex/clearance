<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Optional contract for context models that sit below another context
 * (for example a Store below a Company).
 *
 * Only contextual bindings are inherited: a role bound on the parent context grants its
 * permissions on the child, and a per-context override on the parent applies to the child.
 * Roles themselves stay single-level, and a binding on the child never reaches
 * the parent or its siblings.
 */
interface ClearanceHierarchicalContext
{
    /**
     * Returns the parent context model, or null when this context is a root.
     */
    public function clearanceParentContext(): ?Model;
}
