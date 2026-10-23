<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Tests\Support;

use Illuminate\Database\Eloquent\Model;

class FakeContext extends Model
{
    protected $table = 'fake_contexts';

    protected $guarded = [];
}
