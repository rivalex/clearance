<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

// The installer adds the Tailwind `@source` line for the Clearance views to the host resources/css/app.css,
// only after confirmation, and writes no other file. The host base path is a temporary directory per test.

const T120_LINE = "@source '../../vendor/rivalex/clearance/resources/views';";
const T120_QUESTION = 'Add the Clearance @source line to resources/css/app.css?';

beforeEach(function (): void {
    $this->t120Original = [
        'base' => app()->basePath(),
        'storage' => storage_path(),
        'database' => database_path(),
    ];
    $this->t120Dir = sys_get_temp_dir().'/t120-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->t120Dir.'/resources/css');

    // Only base_path() moves: the marker, the migrations and the database stay where the other installer tests use them.
    app()->setBasePath($this->t120Dir);
    app()->useStoragePath($this->t120Original['storage']);
    app()->useDatabasePath($this->t120Original['database']);

    array_map('unlink', glob(database_path('migrations/*_create_clr_*_table.php')) ?: []);
    $this->runMigrations();
    @unlink(storage_path('.clearance-installed'));
});

afterEach(function (): void {
    array_map('unlink', glob(database_path('migrations/*_create_clr_*_table.php')) ?: []);
    @unlink(storage_path('.clearance-installed'));
    app()->setBasePath($this->t120Original['base']);
    app()->useStoragePath($this->t120Original['storage']);
    app()->useDatabasePath($this->t120Original['database']);
    File::deleteDirectory($this->t120Dir);
});

function t120Css(object $test, string $contents): string
{
    $path = $test->t120Dir.'/resources/css/app.css';
    file_put_contents($path, $contents);

    return $path;
}

it('appends the line after the last @source line', function (): void {
    $path = t120Css($this, "@import 'tailwindcss';\n@source '../views';\n@source '../js';\n\n@theme {\n}\n");

    $this->artisan('clearance:install')
        ->expectsConfirmation(T120_QUESTION, 'yes')
        ->expectsOutputToContain('npm run build')
        ->assertSuccessful();

    expect(file_get_contents($path))->toBe("@import 'tailwindcss';\n@source '../views';\n@source '../js';\n".T120_LINE."\n\n@theme {\n}\n");
});

it('appends the line after the last @import line when there is no @source', function (): void {
    $path = t120Css($this, "@import 'tailwindcss';\n@import '../../vendor/livewire/flux/dist/flux.css';\n\n@theme {\n}\n");

    $this->artisan('clearance:install')
        ->expectsConfirmation(T120_QUESTION, 'yes')
        ->assertSuccessful();

    expect(file_get_contents($path))->toBe("@import 'tailwindcss';\n@import '../../vendor/livewire/flux/dist/flux.css';\n".T120_LINE."\n\n@theme {\n}\n");
});

it('adds a newline first when the anchor line is the last one and has none', function (): void {
    $path = t120Css($this, "@import 'tailwindcss';");

    $this->artisan('clearance:install')
        ->expectsConfirmation(T120_QUESTION, 'yes')
        ->assertSuccessful();

    expect(file_get_contents($path))->toBe("@import 'tailwindcss';\n".T120_LINE."\n");
});

it('is idempotent on a second run', function (): void {
    $path = t120Css($this, "@import 'tailwindcss';\n");

    $this->artisan('clearance:install')->expectsConfirmation(T120_QUESTION, 'yes')->assertSuccessful();
    $afterFirst = file_get_contents($path);

    $this->artisan('clearance:install', ['--force' => true])->assertSuccessful();

    expect(file_get_contents($path))->toBe($afterFirst)
        ->and(substr_count($afterFirst, 'vendor/rivalex/clearance/resources/views'))->toBe(1);
});

it('leaves the file untouched and prints the instructions with --no-interaction', function (): void {
    $original = "@import 'tailwindcss';\n";
    $path = t120Css($this, $original);

    $this->artisan('clearance:install', ['--no-interaction' => true])
        ->expectsOutputToContain(T120_LINE)
        ->expectsOutputToContain('#styling')
        ->expectsOutputToContain('npm run build')
        ->assertSuccessful();

    expect(file_get_contents($path))->toBe($original);
});

it('prints the instructions and creates nothing when the file is missing', function (): void {
    $this->artisan('clearance:install')
        ->expectsOutputToContain(T120_LINE)
        ->expectsOutputToContain('#styling')
        ->assertSuccessful();

    expect(file_exists($this->t120Dir.'/resources/css/app.css'))->toBeFalse()
        ->and(File::allFiles($this->t120Dir))->toBe([]);
});

it('leaves the file byte-identical when the confirmation is declined', function (): void {
    $original = "@import 'tailwindcss';\r\n@source '../views';\r\n";
    $path = t120Css($this, $original);

    $this->artisan('clearance:install')
        ->expectsConfirmation(T120_QUESTION, 'no')
        ->expectsOutputToContain(T120_LINE)
        ->assertSuccessful();

    expect(file_get_contents($path))->toBe($original);
});

it('keeps CRLF line endings when it appends', function (): void {
    $path = t120Css($this, "@import 'tailwindcss';\r\n@source '../views';\r\n");

    $this->artisan('clearance:install')->expectsConfirmation(T120_QUESTION, 'yes')->assertSuccessful();

    expect(file_get_contents($path))->toBe("@import 'tailwindcss';\r\n@source '../views';\r\n".T120_LINE."\r\n");
});

it('writes nothing and prints the line when the file has neither @source nor @import', function (): void {
    $original = "@theme {\n}\n";
    $path = t120Css($this, $original);

    $this->artisan('clearance:install')
        ->expectsOutputToContain(T120_LINE)
        ->assertSuccessful();

    expect(file_get_contents($path))->toBe($original);
});

it('writes nothing and prints the line when the last @import spans two lines', function (): void {
    $original = "@import 'tailwindcss';\n@import url('x')\n  layer(base);\n";
    $path = t120Css($this, $original);

    $this->artisan('clearance:install')
        ->expectsOutputToContain(T120_LINE)
        ->assertSuccessful();

    expect(file_get_contents($path))->toBe($original);
});

it('ignores a @source inside a block comment when looking for the anchor', function (): void {
    $path = t120Css($this, "@import 'tailwindcss';\n/*\n@source '../old';\n*/\n");

    $this->artisan('clearance:install')
        ->expectsConfirmation(T120_QUESTION, 'yes')
        ->assertSuccessful();

    expect(file_get_contents($path))->toBe("@import 'tailwindcss';\n".T120_LINE."\n/*\n@source '../old';\n*/\n");
});
