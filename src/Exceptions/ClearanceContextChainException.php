<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Exceptions;

use RuntimeException;

/**
 * Thrown when a chain of parent contexts contains a cycle or is deeper than
 * ContextService::MAX_CONTEXT_DEPTH levels.
 */
class ClearanceContextChainException extends RuntimeException {}
