<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Exceptions;

/**
 * Thrown when a role assignment violates the declared scope (global|contextual)
 * or the role's context_types binding.
 */
class ClearanceScopeViolationException extends \DomainException {}
