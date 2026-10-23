<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Support;

/**
 * Builds LIKE patterns from user-typed search terms.
 *
 * The wildcard characters of the term are escaped with `!`, so the query must declare it: `LIKE ? ESCAPE '!'`.
 * A backslash is not used because SQLite and SQL Server have no default escape character.
 */
final class LikePattern
{
    /**
     * Returns a "contains" pattern for the term, with `%`, `_`, `[` (a SQL Server character class) and the escape character `!` matched literally.
     */
    public static function contains(string $term): string
    {
        return '%'.str_replace(['!', '%', '_', '['], ['!!', '!%', '!_', '!['], $term).'%';
    }
}
