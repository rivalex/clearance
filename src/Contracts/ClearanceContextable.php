<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Contracts;

interface ClearanceContextable
{
    /**
     * Human-readable label for display (e.g. "Store · Milano · Via Roma 1").
     */
    public function clearanceLabel(): string;
}
