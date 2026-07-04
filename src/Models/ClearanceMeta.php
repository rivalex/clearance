<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Generic metadata store for roles and guards.
 *
 * @property int $id
 * @property string $subject_type 'role' | 'guard'
 * @property string $subject_key role name or guard name
 * @property string|null $display_name
 * @property string|null $description
 * @property string|null $icon_svg
 * @property string|null $color hex e.g. #3b82f6
 */
class ClearanceMeta extends Model
{
    protected $table = 'clr_meta';

    protected $fillable = [
        'subject_type',
        'subject_key',
        'display_name',
        'description',
        'icon_svg',
        'color',
    ];

    /**
     * Retrieve or instantiate meta for a given subject.
     */
    public static function forSubject(string $type, string $key): self
    {
        return self::firstOrNew(
            ['subject_type' => $type, 'subject_key' => $key]
        );
    }
}
