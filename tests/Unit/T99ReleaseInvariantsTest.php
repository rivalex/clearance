<?php

declare(strict_types=1);

/**
 * Release invariants of the package.
 *
 * Source scans written in PHP so they run everywhere the suite runs. Each invariant
 * has a scanner helper, a case that runs it on the real tree and a case that proves
 * the scanner detects the pattern it forbids.
 */

/**
 * Every file under a directory with the given extension, as absolute paths.
 *
 * @return list<string>
 */
function t99Files(string $relativeDir, string $suffix): array
{
    $root = dirname(__DIR__, 2).'/'.$relativeDir;
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), $suffix)) {
            // Forward slashes on every OS, so the suffix checks below also match on Windows.
            $files[] = str_replace('\\', '/', $file->getPathname());
        }
    }

    sort($files);

    return $files;
}

/**
 * Whether the source calls hasRole() with the literal super_admin name.
 */
function t99HasLiteralSuperAdminRoleCheck(string $source): bool
{
    return preg_match('/hasRole\(\s*[\'"]super_admin[\'"]/', $source) === 1;
}

/**
 * Attribute values of x-on: and wire:click attributes that interpolate anything
 * other than a Js::from() call.
 *
 * @return list<string>
 */
function t99UnsafeInterpolatedHandlers(string $blade): array
{
    preg_match_all('/(?:x-on:[\w.:-]+|wire:click(?:\.[\w.]+)?)\s*=\s*"([^"]*)"/', $blade, $matches);

    $unsafe = [];

    foreach ($matches[1] as $value) {
        $remainder = preg_replace('/\{\{ \\\\Illuminate\\\\Support\\\\Js::from\([^{}]*\) \}\}/', '', $value);

        if (str_contains((string) $remainder, '{{') || str_contains((string) $remainder, '{!!')) {
            $unsafe[] = $value;
        }
    }

    return $unsafe;
}

/**
 * Throwable catch blocks whose body never rethrows.
 *
 * @return list<string>
 */
function t99SwallowingThrowableCatches(string $php): array
{
    $found = [];

    if (preg_match_all('/catch\s*\(\s*\\\\?(?:Throwable|Exception)\b[^)]*\)\s*\{/', $php, $matches, PREG_OFFSET_CAPTURE) === 0) {
        return $found;
    }

    foreach ($matches[0] as [$text, $offset]) {
        $depth = 1;
        $position = $offset + strlen($text);
        $start = $position;
        $length = strlen($php);

        while ($position < $length && $depth > 0) {
            $char = $php[$position];
            $depth += $char === '{' ? 1 : ($char === '}' ? -1 : 0);
            $position++;
        }

        $body = substr($php, $start, $position - $start);

        if (preg_match('/\bthrow\b/', $body) !== 1) {
            $found[] = $text;
        }
    }

    return $found;
}

/**
 * Whether the source reads the request-moved auth defaults that Identity pins at boot.
 */
function t99ReadsAuthIdentityConfig(string $source): bool
{
    return str_contains($source, 'auth.defaults.guard') || str_contains($source, 'auth.providers.users.model');
}

/**
 * Lines that test a name against the protected clearance- prefix with str_starts_with().
 *
 * @return list<string>
 */
function t99ClearancePrefixChecks(string $source): array
{
    $lines = [];

    foreach (preg_split('/\R/', $source) ?: [] as $line) {
        if (preg_match('/str_starts_with\([^)]*\'clearance-\'/', $line) === 1) {
            $lines[] = trim($line);
        }
    }

    return $lines;
}

/**
 * Lines that combine the role_id column literal with a raw table query or the configured pivot key.
 *
 * @return list<string>
 */
function t99RawRolePivotKeyLines(string $source): array
{
    $lines = [];

    foreach (preg_split('/\R/', $source) ?: [] as $line) {
        if (str_contains($line, "'role_id'") && (str_contains($line, 'DB::table(') || str_contains($line, 'role_pivot_key'))) {
            $lines[] = trim($line);
        }
    }

    return $lines;
}

it('keeps hasRole(super_admin) out of src outside SuperAdmin (T99.a)', function (): void {
    $offenders = [];

    foreach (t99Files('src', '.php') as $file) {
        if (str_ends_with($file, '/Support/SuperAdmin.php')) {
            continue;
        }

        if (t99HasLiteralSuperAdminRoleCheck((string) file_get_contents($file))) {
            $offenders[] = $file;
        }
    }

    expect($offenders)->toBe([]);
});

it('detects a literal super_admin role check (T99.a scanner)', function (): void {
    expect(t99HasLiteralSuperAdminRoleCheck('$user->hasRole(\'super_admin\')'))->toBeTrue()
        ->and(t99HasLiteralSuperAdminRoleCheck('$user->hasRole("super_admin")'))->toBeTrue()
        ->and(t99HasLiteralSuperAdminRoleCheck('SuperAdmin::is($user)'))->toBeFalse();
});

it('allows only Js::from interpolation inside x-on and wire:click values (T99.b)', function (): void {
    $offenders = [];

    foreach (t99Files('resources/views', '.blade.php') as $file) {
        foreach (t99UnsafeInterpolatedHandlers((string) file_get_contents($file)) as $value) {
            $offenders[] = basename($file).': '.$value;
        }
    }

    expect($offenders)->toBe([]);
});

it('detects a raw interpolation inside an event handler (T99.b scanner)', function (): void {
    $safe = '<button wire:click="open({{ \Illuminate\Support\Js::from($name) }})" x-on:click="$flux.modal({{ \Illuminate\Support\Js::from($m) }}).close()">';
    $raw = '<button wire:click="open(\'{{ $name }}\')">';
    $rawAlpine = '<button x-on:click="go(\'{{ $name }}\')">';

    expect(t99UnsafeInterpolatedHandlers($safe))->toBe([])
        ->and(t99UnsafeInterpolatedHandlers($raw))->toHaveCount(1)
        ->and(t99UnsafeInterpolatedHandlers($rawAlpine))->toHaveCount(1);
});

it('never swallows a Throwable in the commands (T99.c)', function (): void {
    $offenders = [];

    foreach (t99Files('src/Commands', '.php') as $file) {
        foreach (t99SwallowingThrowableCatches((string) file_get_contents($file)) as $catch) {
            $offenders[] = basename($file).': '.$catch;
        }
    }

    expect($offenders)->toBe([]);
});

it('detects a Throwable catch without rethrow (T99.c scanner)', function (): void {
    $swallowing = 'try { run(); } catch (\Throwable $e) { $this->error($e->getMessage()); return 1; }';
    $rethrowing = 'try { run(); } catch (\Throwable $e) { cleanup(); throw $e; }';
    $narrow = 'try { run(); } catch (RuntimeException $e) { return 1; }';

    expect(t99SwallowingThrowableCatches($swallowing))->toHaveCount(1)
        ->and(t99SwallowingThrowableCatches($rethrowing))->toBe([])
        ->and(t99SwallowingThrowableCatches($narrow))->toBe([]);
});

it('reads the auth identity config only in Identity', function (): void {
    $offenders = [];

    foreach (t99Files('src', '.php') as $file) {
        if (str_ends_with($file, '/Support/Identity.php')) {
            continue;
        }

        if (t99ReadsAuthIdentityConfig((string) file_get_contents($file))) {
            $offenders[] = $file;
        }
    }

    expect($offenders)->toBe([]);
});

it('detects a read of the auth identity config', function (): void {
    expect(t99ReadsAuthIdentityConfig("config('auth.defaults.guard')"))->toBeTrue()
        ->and(t99ReadsAuthIdentityConfig("config('auth.providers.users.model')"))->toBeTrue()
        ->and(t99ReadsAuthIdentityConfig('Identity::guard()'))->toBeFalse()
        ->and(t99ReadsAuthIdentityConfig("config('auth.providers.'.\$name.'.model')"))->toBeFalse();
});

it('keeps the clearance- prefix check in PermissionService only', function (): void {
    $offenders = [];

    foreach (t99Files('src/Services', '.php') as $file) {
        if (t99ClearancePrefixChecks((string) file_get_contents($file)) !== [] && ! str_ends_with($file, '/Services/PermissionService.php')) {
            $offenders[] = $file;
        }
    }

    expect($offenders)->toBe([])
        ->and(t99ClearancePrefixChecks((string) file_get_contents(dirname(__DIR__, 2).'/src/Services/PermissionService.php')))->toHaveCount(1);
});

it('detects a clearance- prefix check', function (): void {
    expect(t99ClearancePrefixChecks("return str_starts_with(\$name, 'clearance-')"))->toHaveCount(1)
        ->and(t99ClearancePrefixChecks("return str_starts_with(\$name, 'billing-')"))->toBe([])
        ->and(t99ClearancePrefixChecks('return PermissionService::isProtected($name);'))->toBe([]);
});

it('keeps the role id column literal away from raw table queries and the pivot key in the components', function (): void {
    $offenders = [];

    foreach (t99Files('src/Livewire', '.php') as $file) {
        foreach (t99RawRolePivotKeyLines((string) file_get_contents($file)) as $line) {
            $offenders[] = basename($file).': '.$line;
        }
    }

    expect($offenders)->toBe([]);
});

it('detects the role id literal next to a raw table query or the pivot key', function (): void {
    expect(t99RawRolePivotKeyLines("DB::table('model_has_roles')->where('role_id', \$id)"))->toHaveCount(1)
        ->and(t99RawRolePivotKeyLines("->where(config('permission.column_names.role_pivot_key') ?? 'role_id', \$id)"))->toHaveCount(1)
        ->and(t99RawRolePivotKeyLines("RoleMeta::where('role_id', \$id)->first()"))->toBe([])
        ->and(t99RawRolePivotKeyLines("DB::table('model_has_roles')->where(\$pivotKey, \$id)"))->toBe([]);
});

/**
 * Lines that reach Spatie's user relation (->users(), or 'users' in a relation-loading call) or call can().
 *
 * Both resolve the request guard; identity decisions read Identity and call hasPermissionTo() with the guard.
 *
 * @return list<string>
 */
function t99RequestGuardLines(string $source): array
{
    $lines = [];

    foreach (preg_split('/\R/', $source) ?: [] as $line) {
        if (preg_match('/->users\(|->can\(|\b(?:withCount|withExists|withSum|with|load|loadCount|has|whereHas|doesntHave|whereDoesntHave)\(\s*\[?\s*[\'"]users[\'"]/', $line) === 1) {
            $lines[] = trim($line);
        }
    }

    return $lines;
}

it('keeps the request guard out of src: no Spatie users relation and no can() call', function (): void {
    $offenders = [];

    foreach (t99Files('src', '.php') as $file) {
        foreach (t99RequestGuardLines((string) file_get_contents($file)) as $line) {
            $offenders[] = basename($file).': '.$line;
        }
    }

    expect($offenders)->toBe([]);
});

it('detects a Spatie users relation or a can() call', function (): void {
    expect(t99RequestGuardLines("Role::withCount('users')"))->toHaveCount(1)
        ->and(t99RequestGuardLines('$role->users()->count()'))->toHaveCount(1)
        ->and(t99RequestGuardLines("Role::with(['users'])->get()"))->toHaveCount(1)
        ->and(t99RequestGuardLines('$request->user()?->can($permission)'))->toHaveCount(1)
        ->and(t99RequestGuardLines("Role::withCount('permissions')"))->toBe([])
        ->and(t99RequestGuardLines("\$clearance->canPerform('users')"))->toBe([])
        ->and(t99RequestGuardLines('$user->hasPermissionTo($name, Identity::guard())'))->toBe([]);
});
