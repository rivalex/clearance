<?php

declare(strict_types=1);

// Regression test for F5 (rivalex/clearance security audit,
// docs/plans/security-audit/plan.md): resources/lang/fr/ui.php had drifted from
// the other 8 locales (207 vs 213 keys - a stale `roles.delete` section with a
// single legacy `has_users` string instead of the 7 keys the current
// delete-role.blade.php actually renders). Locks all locales to an identical
// key set against `en` as the canonical source.

/**
 * Recursively flattens a nested translation array into dot-notation keys.
 *
 * @param  array<array-key, mixed>  $array
 * @return array<int, string>
 */
function flattenTranslationKeys(array $array, string $prefix = ''): array
{
    $keys = [];

    foreach ($array as $key => $value) {
        $fullKey = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

        if (is_array($value)) {
            $keys = array_merge($keys, flattenTranslationKeys($value, $fullKey));
        } else {
            $keys[] = $fullKey;
        }
    }

    return $keys;
}

it('every locale exposes the exact same ui.php key set as en', function (): void {
    $langDir = realpath(__DIR__ . '/../../resources/lang');
    $locales = array_diff(scandir($langDir), ['.', '..']);

    $canonical = flattenTranslationKeys(include "{$langDir}/en/ui.php");
    sort($canonical);

    foreach ($locales as $locale) {
        $keys = flattenTranslationKeys(include "{$langDir}/{$locale}/ui.php");
        sort($keys);

        expect($keys)
            ->toBe($canonical, "Locale [{$locale}] key set diverges from [en]. Missing: "
                . implode(', ', array_diff($canonical, $keys)) . '. Extra: '
                . implode(', ', array_diff($keys, $canonical)) . '.');
    }
})->skip(fn () => ! is_dir(realpath(__DIR__ . '/../../resources/lang')), 'lang directory not found');
