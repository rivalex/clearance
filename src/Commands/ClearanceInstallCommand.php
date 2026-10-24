<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Rivalex\Clearance\Exceptions\ClearanceProtectedResourceException;
use Rivalex\Clearance\Exceptions\ClearanceScopeViolationException;
use Rivalex\Clearance\Models\ClearanceSettings;
use Rivalex\Clearance\Models\Permission;
use Rivalex\Clearance\Models\RoleMeta;
use Rivalex\Clearance\Services\PermissionService;
use Rivalex\Clearance\Services\UserClearanceService;
use Rivalex\Clearance\Support\Identity;
use Rivalex\Clearance\Support\SuperAdmin;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Traits\HasRoles;

class ClearanceInstallCommand extends Command
{
    use ConfirmableTrait;

    /** Tables owned by Clearance; all must exist before the install is marked complete. */
    private const TABLES = [
        'clr_meta',
        'clr_role_meta',
        'clr_guards',
        'clr_settings',
        'clr_role_ctx',
        'clr_ctx_overrides',
    ];

    /** Host stylesheet, relative to the host base path, that receives the Tailwind `@source` line. */
    private const HOST_STYLESHEET = 'resources/css/app.css';

    /** Path fragment that marks a host stylesheet which already scans the Clearance views. */
    private const VIEWS_SOURCE_PATH = 'vendor/rivalex/clearance/resources/views';

    /** The line the host stylesheet needs so Tailwind compiles the classes written in the Clearance views. */
    private const SOURCE_LINE = "@source '../../vendor/rivalex/clearance/resources/views';";

    /** File name suffixes of the Clearance migrations published into the host. */
    private const MIGRATION_SUFFIXES = [
        '_create_clr_role_meta_table.php',
        '_create_clr_role_ctx_table.php',
        '_create_clr_guards_table.php',
        '_create_clr_meta_table.php',
        '_create_clr_settings_table.php',
        '_create_clr_ctx_overrides_table.php',
    ];

    /** @var string */
    protected $signature = 'clearance:install
                            {--user= : User ID to assign the access permission to}
                            {--role= : Role name to assign the access permission to}
                            {--super-admin-role= : Name of existing role to promote to super_admin}
                            {--force : Re-run even if already installed, and skip the production confirmation}';

    /** @var string */
    protected $description = 'Install Clearance: publish config, run migrations, create access permission.';

    public function __construct(
        private readonly PermissionService $permissionService,
    ) {
        parent::__construct();
    }

    /**
     * Execute the install sequence (V1, V10). Migration exceptions propagate to the caller.
     */
    public function handle(): int
    {
        $markerPath = storage_path('.clearance-installed');

        // Upgrade step: runs before the marker check so an already installed host converts without --force.
        if (Schema::hasTable('clr_settings')) {
            $this->convertLegacyDefaultRole();
        }

        if (! $this->option('force') && file_exists($markerPath)) {
            $this->info('Clearance already installed. Use --force to re-run.');

            return self::SUCCESS;
        }

        // In production the install asks for confirmation; --force skips it as well.
        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        // Refuse before any write: a bad --user value or a second super_admin leaves the host untouched.
        if (($failure = $this->preflight()) !== null) {
            $this->error($failure);

            return self::FAILURE;
        }

        $this->info('Publishing Clearance config...');
        $this->callSilently('vendor:publish', [
            '--tag' => 'clearance-config',
            '--provider' => 'Rivalex\\Clearance\\ClearanceServiceProvider',
        ]);

        if (! $this->ensureSpatieInstalled()) {
            return self::FAILURE;
        }

        $this->info('Publishing Clearance migrations...');
        $this->callSilently('vendor:publish', [
            '--tag' => 'clearance-migrations',
            '--provider' => 'Rivalex\\Clearance\\ClearanceServiceProvider',
        ]);

        // Only the Clearance migration files run: pending migrations of the host are left alone.
        $paths = $this->clearanceMigrationPaths();

        if ($paths === []) {
            $this->error('No Clearance migration file was found in '.database_path('migrations').' after publishing. Installation aborted.');

            return self::FAILURE;
        }

        $this->info('Running migrations...');
        // A migration exception propagates (no marker is written); a non-zero exit code is checked below.
        $exitCode = $this->callSilently('migrate', ['--path' => $paths, '--realpath' => true, '--force' => true]);

        if ($exitCode !== self::SUCCESS) {
            $this->error('Migrations failed (exit code '.$exitCode.'). Fix the error above and re-run clearance:install.');

            return self::FAILURE;
        }

        $missing = array_values(array_filter(self::TABLES, static fn (string $table): bool => ! Schema::hasTable($table)));

        if ($missing !== []) {
            $this->error('Clearance tables missing after migrating: '.implode(', ', $missing).'. Installation aborted.');

            return self::FAILURE;
        }

        $this->configureTailwind();

        $this->convertLegacyDefaultRole();

        $permissionName = config('clearance.access_permission', 'clearance-access');
        $guard = Identity::guard();

        /** @var Permission $permission */
        $permission = Permission::firstOrCreate(
            ['name' => $permissionName, 'guard_name' => $guard],
        );

        $this->info("Permission [{$permissionName}] ready.");

        // Seed fine-grained capability permissions (one per panel section).
        foreach ([
            'clearance-permissions-write',
            'clearance-roles-write',
            'clearance-guards-write',
            'clearance-settings-write',
            'clearance-users-write',
        ] as $capName) {
            Permission::firstOrCreate(['name' => $capName, 'guard_name' => $guard]);
            $this->info("Permission [{$capName}] ready.");
        }

        $superAdminRole = $this->createSuperAdminRole($guard, $permission);

        if ($userId = $this->option('user')) {
            $this->assignSuperAdminToUser((string) $userId, $superAdminRole);
        }

        if ($roleName = $this->option('role')) {
            $this->assignToRole((string) $roleName, $permission, $guard);
        }

        file_put_contents($markerPath, date('Y-m-d H:i:s'));
        $this->info('Clearance installed successfully.');

        return self::SUCCESS;
    }

    /**
     * Adds the Tailwind `@source` line for the Clearance views to the host stylesheet (the panel uses the host theme only).
     *
     * Only `resources/css/app.css` is ever written, and only after an interactive confirmation. A missing file, a
     * file with no `@source` or `@import` line to anchor on, or a non-interactive run changes nothing and prints
     * the line to add. A stylesheet that already names the Clearance views is left alone. Comments are ignored when
     * looking for an anchor, and a last anchor line that does not end its statement with `;` (a multi-line `@import`
     * or `@source`) means nothing is written.
     */
    private function configureTailwind(): void
    {
        $this->info('Configuring Tailwind...');

        $path = base_path(self::HOST_STYLESHEET);
        $contents = is_file($path) ? file_get_contents($path) : false;

        if ($contents !== false && str_contains($contents, self::VIEWS_SOURCE_PATH)) {
            $this->info('The host stylesheet already scans the Clearance views.');

            return;
        }

        $updated = $contents === false ? null : $this->withSourceLine($contents);

        if ($updated !== null && $this->input->isInteractive()
            && $this->confirm('Add the Clearance @source line to '.self::HOST_STYLESHEET.'?', true)) {
            file_put_contents($path, $updated);
            $this->info('Added to '.self::HOST_STYLESHEET.': '.self::SOURCE_LINE);
            $this->warn('Rebuild the host assets so the panel is styled (npm run build).');

            return;
        }

        $this->warn('Add this line to '.self::HOST_STYLESHEET.' (next to your Flux import), then rebuild the host assets (npm run build):');
        $this->line('  '.self::SOURCE_LINE);
        $this->line('See the "Styling" section of the README (#styling).');
    }

    /**
     * The stylesheet with the Clearance `@source` line after its last `@source` line, or after its last `@import`
     * line when it has no `@source`. Null when it has neither line to anchor on, or when the last anchor line does
     * not end with `;` (a multi-line statement). Comments are ignored when looking for an anchor.
     */
    private function withSourceLine(string $css): ?string
    {
        // Comments are blanked out with the same byte length, so offsets on $scan are valid on $css.
        $scan = preg_replace_callback('#/\*.*?\*/#s', static fn (array $m): string => (string) preg_replace('/[^\r\n]/', ' ', $m[0]), $css);

        if ($scan === null) {
            return null;
        }

        foreach (['source', 'import'] as $directive) {
            if (preg_match_all('/^[ \t]*@'.$directive.'\b[^\r\n]*(\r?\n|\z)/m', $scan, $matches, PREG_OFFSET_CAPTURE) < 1) {
                continue;
            }

            $last = end($matches[0]);

            if (! str_ends_with(rtrim($last[0]), ';')) {
                return null;
            }

            $eol = str_contains($css, "\r\n") ? "\r\n" : "\n";
            $end = $last[1] + strlen($last[0]);
            $glue = $matches[1][count($matches[1]) - 1][0] === '' ? $eol : '';

            return substr($css, 0, $end).$glue.self::SOURCE_LINE.$eol.substr($css, $end);
        }

        return null;
    }

    /**
     * Converts a legacy `default_role` name setting to `default_role_id` (idempotent, silent when there is nothing to convert).
     *
     * The name is resolved on the identity guard. When it does not resolve, an interactive run offers the eligible roles
     * by name (nobody types an id); a non-interactive run leaves no default role. The legacy row is always deleted.
     */
    private function convertLegacyDefaultRole(): void
    {
        $legacy = ClearanceSettings::where('key', 'default_role')->first();

        if ($legacy === null) {
            return;
        }

        $stored = ClearanceSettings::get('default_role_id');

        if (is_string($stored) && ctype_digit($stored)) {
            ClearanceSettings::where('key', 'default_role')->delete();

            return;
        }

        $name = (string) $legacy->value;

        // A host that had cleared the default role left an empty row: remove it without any output.
        if ($name === '') {
            ClearanceSettings::where('key', 'default_role')->delete();

            return;
        }

        $guard = Identity::guard();

        /** @var Role|null $role */
        $role = Role::where('name', $name)->where('guard_name', $guard)->first();

        $ineligible = false;

        if ($role !== null) {
            try {
                app(UserClearanceService::class)->assertCanBeDefaultRole($role);
            } catch (ClearanceScopeViolationException|ClearanceProtectedResourceException) {
                $role = null;
                $ineligible = true;
            }
        }

        if ($role !== null) {
            ClearanceSettings::set('default_role_id', (string) $role->getKey());
            $this->info("Default role [{$role->name}] converted to id {$role->getKey()}.");
        } else {
            $this->warn($ineligible
                ? "Legacy default role [{$name}] cannot be the default role on guard [{$guard}]: no default role is set."
                : "Legacy default role [{$name}] not found on guard [{$guard}]: no default role is set.");

            if ($this->input->isInteractive()) {
                $this->chooseDefaultRole($guard);
            }
        }

        ClearanceSettings::where('key', 'default_role')->delete();
    }

    /**
     * Offers the roles of the guard that may be a default role, by name, and stores the chosen id.
     */
    private function chooseDefaultRole(string $guard): void
    {
        $service = app(UserClearanceService::class);

        $eligible = Role::where('guard_name', $guard)
            ->orderBy('name')
            ->get()
            ->filter(function (Role $candidate) use ($service): bool {
                try {
                    $service->assertCanBeDefaultRole($candidate);
                } catch (ClearanceScopeViolationException|ClearanceProtectedResourceException) {
                    return false;
                }

                return true;
            });

        $none = 'none';
        $options = $eligible->pluck('name')->prepend($none)->values()->all();
        $choice = $this->choice('Choose the default role for new users', $options, 0);

        if ($choice === $none) {
            return;
        }

        /** @var Role|null $chosen */
        $chosen = $eligible->firstWhere('name', $choice);

        if ($chosen === null) {
            return;
        }

        ClearanceSettings::set('default_role_id', (string) $chosen->getKey());
        $this->info("Default role set to [{$chosen->name}] (id {$chosen->getKey()}).");
    }

    /**
     * Publishes and runs only the Spatie Permission migration if the roles table is absent.
     *
     * Returns false when the migration file is still missing after publishing, so the install stops.
     */
    private function ensureSpatieInstalled(): bool
    {
        if (Schema::hasTable((string) config('permission.table_names.roles', 'roles'))) {
            return true;
        }

        $this->info('Spatie Permission tables not found. Publishing and running Spatie migrations...');

        $this->callSilently('vendor:publish', [
            '--tag' => 'permission-migrations',
            '--provider' => 'Spatie\\Permission\\PermissionServiceProvider',
        ]);

        $files = glob(database_path('migrations/*_create_permission_tables.php')) ?: [];

        if ($files === []) {
            $this->error('Spatie Permission migration not found in '.database_path('migrations').' after publishing. Installation aborted.');

            return false;
        }

        sort($files);

        $this->call('migrate', ['--path' => $files, '--realpath' => true, '--force' => true]);

        return true;
    }

    /**
     * Absolute paths of the Clearance migration files in the host migrations folder, sorted.
     *
     * Matches by file name suffix because publishing reuses an existing file with the same suffix.
     *
     * @return array<int, string>
     */
    private function clearanceMigrationPaths(): array
    {
        $paths = [];

        foreach (self::MIGRATION_SUFFIXES as $suffix) {
            foreach (glob(database_path('migrations/*'.$suffix)) ?: [] as $path) {
                $paths[] = $path;
            }
        }

        sort($paths);

        return $paths;
    }

    /**
     * Validates the options that need no write. Returns an error message, or null when the install may proceed.
     */
    private function preflight(): ?string
    {
        $userId = $this->option('user');

        if ($userId !== null && $userId !== '') {
            $userModelClass = $this->userModelClass();

            if ($userModelClass === null || ! class_exists($userModelClass)) {
                return 'Cannot resolve the user model for guard ['.Identity::guard().']: set clearance.user_model.';
            }

            /** @var Model $probe */
            $probe = new $userModelClass;

            if ($probe->getKeyType() === 'int' && filter_var($userId, FILTER_VALIDATE_INT) === false) {
                return "Invalid --user value [{$userId}]: the user model key is an integer.";
            }
        }

        $explicitName = $this->option('super-admin-role');
        $guard = Identity::guard();

        if (
            $explicitName !== null
            && $explicitName !== SuperAdmin::ROLE
            && $this->superAdminExists($guard)
        ) {
            return "A [super_admin] role already exists on guard [{$guard}]: refusing to promote [{$explicitName}] to super_admin.";
        }

        return null;
    }

    /**
     * Whether a super_admin role already exists on the guard (false before the Spatie tables exist).
     */
    private function superAdminExists(string $guard): bool
    {
        if (! Schema::hasTable((string) config('permission.table_names.roles', 'roles'))) {
            return false;
        }

        return Role::where('name', SuperAdmin::ROLE)->where('guard_name', $guard)->exists();
    }

    /**
     * The configured user model class.
     */
    private function userModelClass(): ?string
    {
        return Identity::userModel();
    }

    /**
     * Resolves the super_admin role: creates it, promotes an existing alias, or
     * interactively prompts the user when a candidate role is detected.
     * Permission attachment is always additive - existing permissions are never removed.
     */
    private function createSuperAdminRole(string $guard, Permission $accessPermission): Role
    {
        $explicitName = $this->option('super-admin-role');

        if ($explicitName !== null) {
            $role = Role::where('name', $explicitName)->where('guard_name', $guard)->first();
            if ($role === null) {
                $this->warn("Role [{$explicitName}] not found for guard [{$guard}] - falling back to default super_admin.");
                $role = $this->getOrCreateDefaultSuperAdmin($guard);
            } else {
                $this->renamePromotedRole($role);
            }
        } else {
            // An existing super_admin on the guard is never joined by a second promotion: skip detection.
            $candidates = $this->superAdminExists($guard)
                ? new Collection
                : $this->detectSuperAdminCandidates($guard);

            if ($candidates->isNotEmpty() && ! $this->option('no-interaction')) {
                $role = $this->promptForCandidate($candidates, $guard);
            } else {
                if ($candidates->isNotEmpty()) {
                    $names = $candidates->pluck('name')->implode(', ');
                    $this->warn("Detected existing super-admin-like role(s): [{$names}]. Creating separate 'super_admin'. Use --super-admin-role=NAME to promote one.");
                }
                $role = $this->getOrCreateDefaultSuperAdmin($guard);
            }
        }

        $this->attachClearancePermissions($role, $guard, $accessPermission);

        // Always lock the role and reset its meta, whether created, promoted or found.
        RoleMeta::updateOrCreate(
            ['role_id' => $role->id],
            ['is_locked' => true, 'scope' => 'global', 'parent_role_id' => null, 'context_types' => null],
        );

        return $role;
    }

    /**
     * Finds roles with names matching common super-admin aliases (SQLite-safe: PHP-side filter).
     *
     * @return Collection<int, Role>
     */
    private function detectSuperAdminCandidates(string $guard): Collection
    {
        return Role::where('guard_name', $guard)
            ->get()
            ->filter(fn (Role $r) => $r->name !== 'super_admin'
                && preg_match('/^(super[_\-\s]?admin|root|owner)$/i', $r->name) === 1);
    }

    /**
     * Prompts the user to select an existing candidate role or create a fresh super_admin.
     */
    private function promptForCandidate(Collection $candidates, string $guard): Role
    {
        $createNew = '- create new super_admin -';
        $options = $candidates->pluck('name')->prepend($createNew)->values()->all();

        $choice = $this->choice(
            'Existing super-admin-like role(s) detected. Select one to promote, or create new:',
            $options,
            0,
        );

        if ($choice === $createNew) {
            return $this->getOrCreateDefaultSuperAdmin($guard);
        }

        /** @var Role $role */
        $role = $candidates->firstWhere('name', $choice);
        $this->renamePromotedRole($role);

        return $role;
    }

    /**
     * Renames an existing role to super_admin, preserving all user assignments (FK on role_id).
     */
    private function renamePromotedRole(Role $role): void
    {
        if ($role->name === 'super_admin') {
            return;
        }

        $oldName = $role->name;
        $role->update(['name' => 'super_admin']);
        $this->info("Renamed role [{$oldName}] → [super_admin] - all user assignments preserved (FK on role_id).");
    }

    /**
     * Returns the existing super_admin role or creates it fresh.
     */
    private function getOrCreateDefaultSuperAdmin(string $guard): Role
    {
        return Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => $guard]);
    }

    /**
     * Attaches missing clearance-* permissions additively (never removes existing permissions).
     */
    private function attachClearancePermissions(Role $role, string $guard, Permission $accessPermission): void
    {
        // The configured access permission may not start with "clearance-": attach it explicitly.
        $clearancePermissions = Permission::where('guard_name', $guard)
            ->where('name', 'like', 'clearance-%')
            ->get()
            ->push($accessPermission)
            ->unique('name');

        $existingNames = $role->permissions->pluck('name')->all();
        $missing = $clearancePermissions->reject(fn (Permission $p) => in_array($p->name, $existingNames, true));

        if ($missing->isEmpty()) {
            $this->info("Role [{$role->name}] already has all clearance permissions.");

            return;
        }

        $role->givePermissionTo($missing);
        $this->info("Role [{$role->name}] ready - {$missing->count()} clearance permission(s) added (total: {$role->fresh()->permissions->count()}).");
    }

    /**
     * Assigns the super_admin role to a user by ID (the raw option value is passed to find()).
     */
    private function assignSuperAdminToUser(string $userId, Role $superAdminRole): void
    {
        $userModelClass = $this->userModelClass();

        /** @var Model|null $user */
        $user = $userModelClass::find($userId);

        if ($user === null) {
            $this->warn("User [{$userId}] not found - super_admin role not assigned.");

            return;
        }

        if (! in_array(HasRoles::class, class_uses_recursive($user)) || ! method_exists($user, 'assignRole')) {
            $this->warn('User model ['.get_class($user).'] does not use HasRoles (or Rivalex\\Clearance\\Traits\\HasClearance) - role not assigned.');
            $this->warn('Add `use \\Rivalex\\Clearance\\Traits\\HasClearance;` (includes HasRoles) to your User model, then re-run with --force.');

            return;
        }

        $user->assignRole($superAdminRole);

        $identifier = $user->getAttribute('email') ?? $user->getAttribute('name') ?? $userId;
        $this->info("Role [super_admin] assigned to user [{$userId}] ({$identifier}).");
    }

    /**
     * Assigns the access permission to a role (created if absent).
     */
    private function assignToRole(string $roleName, Permission $permission, string $guard): void
    {
        /** @var Role $role */
        $role = Role::firstOrCreate(
            ['name' => $roleName, 'guard_name' => $guard],
        );

        $this->permissionService->assignToRole($role, $permission);
        $this->info("Permission assigned to role [{$roleName}].");
    }
}
