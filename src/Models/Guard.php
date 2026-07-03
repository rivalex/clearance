<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ## Guard Model Class
 *
 * Persists custom guards managed via Clearance UI.
 *
 * @property int $id
 * @property string $name
 * @property string $driver
 * @property string|null $provider
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class Guard extends Model
{
    protected $table = 'clr_guards';

    protected $fillable = [
        'name',
        'driver',
        'provider',
    ];
}
