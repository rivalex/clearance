<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Exceptions;

/**
 * Thrown when an operation targets a protected resource:
 * a locked role (rename/delete), the super_admin role (clearance-permission changes),
 * or a built-in clearance-* permission (rename/delete).
 */
class ClearanceProtectedResourceException extends \DomainException {}
