<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Key/value store for Clearance runtime settings.
 *
 * @property int $id
 * @property string $key
 * @property string|null $value
 */
class ClearanceSettings extends Model
{
    protected $table = 'clr_settings';

    protected $fillable = ['key', 'value'];

    /**
     * Get a setting value by key, with optional default.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $record = self::where('key', $key)->first();

        return $record ? $record->value : $default;
    }

    /**
     * Set (upsert) a setting value by key.
     */
    public static function set(string $key, mixed $value): void
    {
        self::updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );
    }
}
