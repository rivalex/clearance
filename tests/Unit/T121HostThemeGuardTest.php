<?php

declare(strict_types=1);

/**
 * Guard for the host theme rule: the panel ships no theme of its own.
 *
 * The panel views use the host's Tailwind tokens: `zinc-*` neutrals and the `accent` family.
 * No view may carry the retired 1.x CSS hooks, a stylesheet or a font family, and no PHP file
 * under src/ may build a colour utility class name (the host compiles literal classes only).
 */

/**
 * Every file under a directory, as absolute paths with forward slashes.
 *
 * @return list<string>
 */
function t121Files(string $relativeDir): array
{
    $root = dirname(__DIR__, 2).'/'.$relativeDir;
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $files[] = str_replace('\\', '/', $file->getPathname());
        }
    }

    sort($files);

    return $files;
}

/**
 * The patterns a view must never contain.
 *
 * @return list<string>
 */
function t121ForbiddenViewPatterns(): array
{
    return [
        '/\bgray-\d/',
        '/-black-\d/',
        '/crud-pills/',
        '/clr-logo/',
        '/clr-watermark/',
        '/dark-scope/',
        '/clearance\.min\.css/',
        '/<style/',
        '/font-(sans|serif|body|inter)\b/',
    ];
}

/**
 * Pattern for a colour utility class written as a PHP string literal.
 */
function t121ClassStringPattern(): string
{
    return '/\'(bg|text|border|ring)-[a-z]+-\d{2,3}/';
}

/**
 * The forbidden patterns found in a source string.
 *
 * @param  list<string>  $patterns
 * @return list<string>
 */
function t121Matches(string $source, array $patterns): array
{
    return array_values(array_filter($patterns, static fn (string $pattern): bool => preg_match($pattern, $source) === 1));
}

it('keeps every panel view on host tokens', function (): void {
    $files = t121Files('resources/views');

    expect($files)->not->toBeEmpty();

    $violations = [];

    foreach ($files as $file) {
        foreach (t121Matches((string) file_get_contents($file), t121ForbiddenViewPatterns()) as $pattern) {
            $violations[] = basename($file).' matches '.$pattern;
        }
    }

    expect($violations)->toBe([]);
});

it('builds no colour class name in PHP under src', function (): void {
    $violations = [];

    foreach (t121Files('src') as $file) {
        if (! str_ends_with($file, '.php')) {
            continue;
        }

        if (t121Matches((string) file_get_contents($file), [t121ClassStringPattern()]) !== []) {
            $violations[] = $file;
        }
    }

    expect($violations)->toBe([]);
});

it('detects each forbidden pattern on a violating sample', function (): void {
    $samples = [
        '<p class="text-gray-500">' => '/\bgray-\d/',
        '<p class="text-black-500">' => '/-black-\d/',
        '<label class="crud-pills">' => '/crud-pills/',
        '<div class="clr-logo">' => '/clr-logo/',
        '<div class="clr-watermark">' => '/clr-watermark/',
        '<x-clearance::dark-scope>' => '/dark-scope/',
        '<link href="clearance.min.css">' => '/clearance\.min\.css/',
        '<style>p{}</style>' => '/<style/',
        '<p class="font-sans">' => '/font-(sans|serif|body|inter)\b/',
    ];

    foreach ($samples as $sample => $pattern) {
        expect(t121Matches($sample, t121ForbiddenViewPatterns()))->toContain($pattern);
    }

    expect(t121Matches('<p class="text-zinc-500 text-accent-content font-mono">', t121ForbiddenViewPatterns()))->toBe([])
        ->and(t121Matches("\$class = 'bg-red-600';", [t121ClassStringPattern()]))->not->toBe([])
        ->and(t121Matches("\$color = 'green';", [t121ClassStringPattern()]))->toBe([]);
});
