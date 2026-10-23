<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Tests\Support;

use Rivalex\Clearance\Models\Permission;
use Rivalex\Clearance\Services\PermissionService;
use RuntimeException;

/**
 * PermissionService whose second delete() fails with an unexpected exception (T95).
 */
class T95ThrowingPermissionService extends PermissionService
{
    private int $deletes = 0;

    /**
     * @throws RuntimeException on the second call.
     */
    public function delete(Permission $permission): void
    {
        $this->deletes++;

        if ($this->deletes === 2) {
            throw new RuntimeException('T95 unexpected failure');
        }

        parent::delete($permission);
    }
}
