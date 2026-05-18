<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Services;

use Rivalex\Clearance\Models\ClearanceMeta;
use Rivalex\Clearance\Support\SvgSanitizer;

/**
 * Persists display metadata (icon, colour, display name, description) for roles and guards.
 */
class MetaService
{
    /**
     * Create or update meta for a subject. Sanitizes SVG before storage.
     */
    public function update(string $type, string $key, array $data): ClearanceMeta
    {
        $sanitizedSvg = SvgSanitizer::sanitize($data['icon_svg'] ?: null);

        return ClearanceMeta::updateOrCreate(
            ['subject_type' => $type, 'subject_key' => $key],
            [
                'display_name' => $data['display_name'] ?: null,
                'description'  => $data['description'] ?: null,
                'color'        => $data['color'] ?: null,
                'icon_svg'     => $sanitizedSvg,
            ]
        );
    }
}
