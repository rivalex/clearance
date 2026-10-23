<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Support;

/**
 * Builds the URL of a package asset served by the `clearance.assets` route.
 *
 * The URL carries a short content hash (`?v=`) so the immutable, year-long cache of the asset
 * response is busted whenever the file changes.
 */
final class Asset
{
    /** @var array<string, string> Query string cache, per process. */
    private static array $versions = [];

    /**
     * The asset URL for a path relative to `src/dist` or `resources`, with a `?v=` content hash.
     */
    public static function url(string $path): string
    {
        $url = route('clearance.assets', $path);

        $version = self::$versions[$path] ??= self::hash($path);

        return $version === '' ? $url : $url.'?v='.$version;
    }

    /**
     * Short content hash of the asset file, or an empty string when the file cannot be found.
     */
    private static function hash(string $path): string
    {
        $base = dirname(__DIR__, 2);

        foreach ([$base.'/src/dist', $base.'/resources'] as $root) {
            $root = realpath($root);
            $candidate = $root === false ? false : realpath($root.DIRECTORY_SEPARATOR.$path);

            if ($candidate !== false && str_starts_with($candidate, $root.DIRECTORY_SEPARATOR) && is_file($candidate)) {
                $hash = hash_file('xxh128', $candidate);

                return $hash === false ? '' : substr($hash, 0, 10);
            }
        }

        return '';
    }
}
