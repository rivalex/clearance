<?php

namespace Rivalex\Clearance\Commands;

use Illuminate\Console\Command;

class ClearanceCommand extends Command
{
    public $signature = 'clearance';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
