<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Tests\Support;

use Rivalex\Clearance\Services\ContextService;

/**
 * ContextService that counts how many times its memo is flushed.
 */
class T97FlushSpyContextService extends ContextService
{
    public int $flushes = 0;

    /**
     * Counts the call, then clears the memo as the parent does.
     */
    public function flush(): void
    {
        $this->flushes++;

        parent::flush();
    }
}
