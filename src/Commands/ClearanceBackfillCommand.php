<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Rivalex\Clearance\Models\ClearanceMeta;
use Rivalex\Clearance\Models\Guard;
use Rivalex\Clearance\Models\RoleMeta;
use Spatie\Permission\Models\Role;

class ClearanceBackfillCommand extends Command
{
    /** @var string */
    protected $signature = 'clearance:backfill
                            {--only= : Comma-separated subset to backfill: meta,roles,guards (default: all)}
                            {--dry-run : Preview what would be inserted without writing to the database}';

    /** @var string */
    protected $description = 'Backfill Clearance metadata for pre-existing Spatie roles and config-defined guards.';

    private const VALID_SECTIONS = ['meta', 'roles', 'guards'];

    /**
     * Execute the backfill sequence.
     */
    public function handle(): int
    {
        $sections = $this->resolveSections();

        if ($sections === null) {
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN — no database writes will occur.');
        }

        $counts = [];

        if (in_array('meta', $sections, true)) {
            $counts['meta'] = $this->backfillMeta($dryRun);
        }

        if (in_array('roles', $sections, true)) {
            $counts['roles'] = $this->backfillRoleMeta($dryRun);
        }

        if (in_array('guards', $sections, true)) {
            $counts['guards'] = $this->backfillGuards($dryRun);
        }

        $verb = $dryRun ? 'would be inserted' : 'inserted/skipped (idempotent)';

        foreach ($counts as $section => $n) {
            $this->info("[{$section}] {$n} row(s) {$verb}");
        }

        return self::SUCCESS;
    }

    /**
     * Parses and validates the --only option.
     *
     * @return array<string>|null  null on invalid input
     */
    private function resolveSections(): ?array
    {
        $raw = $this->option('only');

        if ($raw === null || $raw === '') {
            return self::VALID_SECTIONS;
        }

        $requested = array_map('trim', explode(',', $raw));
        $invalid   = array_diff($requested, self::VALID_SECTIONS);

        if ($invalid !== []) {
            $this->error('Invalid --only value(s): ' . implode(', ', $invalid) . '. Valid: ' . implode(', ', self::VALID_SECTIONS));

            return null;
        }

        return $requested;
    }

    /**
     * Seeds clr_meta display_name for every Spatie role without an existing meta row.
     */
    private function backfillMeta(bool $dryRun): int
    {
        $count = 0;

        foreach (Role::all() as $role) {
            $exists = ClearanceMeta::where('subject_type', 'role')
                ->where('subject_key', $role->name)
                ->exists();

            if ($exists) {
                continue;
            }

            $count++;

            if (! $dryRun) {
                ClearanceMeta::create([
                    'subject_type' => 'role',
                    'subject_key'  => $role->name,
                    'display_name' => Str::headline($role->name),
                ]);
            }
        }

        return $count;
    }

    /**
     * Seeds clr_role_meta defaults for every Spatie role without an existing meta row.
     */
    private function backfillRoleMeta(bool $dryRun): int
    {
        $count = 0;

        foreach (Role::all() as $role) {
            if (RoleMeta::where('role_id', $role->id)->exists()) {
                continue;
            }

            $count++;

            if (! $dryRun) {
                RoleMeta::create([
                    'role_id'   => $role->id,
                    'scope'     => RoleMeta::SCOPE_GLOBAL,
                    'is_locked' => false,
                ]);
            }
        }

        return $count;
    }

    /**
     * Imports guards from config/auth.php into clr_guards, filtered by allowed_guard_drivers.
     */
    private function backfillGuards(bool $dryRun): int
    {
        /** @var array<string, array<string, string>> $authGuards */
        $authGuards     = config('auth.guards', []);
        $allowedDrivers = config('clearance.allowed_guard_drivers', ['session', 'token', 'jwt', 'passport', 'sanctum']);
        $count          = 0;

        foreach ($authGuards as $name => $cfg) {
            $driver = $cfg['driver'] ?? 'session';

            if (! in_array($driver, $allowedDrivers, true)) {
                continue;
            }

            if (Guard::where('name', $name)->exists()) {
                continue;
            }

            $count++;

            if (! $dryRun) {
                Guard::create([
                    'name'     => $name,
                    'driver'   => $driver,
                    'provider' => $cfg['provider'] ?? null,
                ]);
            }
        }

        return $count;
    }
}
