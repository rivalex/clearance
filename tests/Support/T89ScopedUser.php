<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Tests\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * FakeEloquentUser with a global scope that hides every row, as a tenant scope hides the users of
 * another tenant. A plain find() never returns it; a lookup that ignores global scopes does.
 */
class T89ScopedUser extends FakeEloquentUser
{
    protected $table = 'fake_users';

    protected static function booted(): void
    {
        static::addGlobalScope('t89-hide-everything', static function (Builder $query): void {
            $query->whereRaw('1 = 0');
        });
    }
}
