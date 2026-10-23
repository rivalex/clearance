<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Services;

use Illuminate\Contracts\Config\Repository;
use Rivalex\Clearance\Exceptions\ClearanceProtectedResourceException;
use Rivalex\Clearance\Models\Guard;
use Rivalex\Clearance\Models\Permission;
use Spatie\Permission\Models\Role;

class GuardService
{
    public function __construct(
        private readonly Repository $config,
    ) {}

    /**
     * Returns all guards to manage, keyed by guard name.
     *
     * Starts from auth.guards (narrowed by clearance.guards when set). A database row named like a
     * config guard never overrides it: it only adds its `id` and `is_db` flag. A database row with a
     * new name is added only when its driver is in clearance.allowed_guard_drivers, and only those
     * names are written to auth.guards at runtime.
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        $authGuards = (array) $this->config->get('auth.guards', []);
        $override = $this->config->get('clearance.guards', []);

        // 1. Start with auth.guards or override filter
        if (! empty($override)) {
            $base = array_filter(
                $authGuards,
                static fn (string $key): bool => in_array($key, $override, strict: true),
                ARRAY_FILTER_USE_KEY,
            );
        } else {
            $base = $authGuards;
        }

        // 2. Merge with guards from DB
        try {
            $rows = Guard::all();
        } catch (\Exception $e) {
            // Migration might not be run yet
            return $base;
        }

        $allowedDrivers = (array) $this->config->get(
            'clearance.allowed_guard_drivers',
            ['session', 'token', 'jwt', 'passport', 'sanctum'],
        );

        $merged = $base;
        $runtime = [];

        foreach ($rows as $row) {
            $name = (string) $row->name;

            if (array_key_exists($name, $authGuards)) {
                if (array_key_exists($name, $base)) {
                    // Config guard: keep its driver and provider, only mark the database row.
                    $merged[$name] = array_merge($base[$name], ['id' => $row->id, 'is_db' => true]);
                } elseif ($this->entryMatchesRow($authGuards[$name], $row) && in_array($row->driver, $allowedDrivers, true)) {
                    // Database guard injected into auth.guards at boot: listed even though the clearance.guards filter excludes it.
                    $merged[$name] = $this->databaseEntry($row);
                }

                continue;
            }

            if (! in_array($row->driver, $allowedDrivers, true)) {
                continue;
            }

            $merged[$name] = $this->databaseEntry($row);
            $runtime[$name] = ['driver' => $row->driver, 'provider' => $row->provider];
        }

        // Make the new database guards known to Laravel at runtime, never replacing a config guard.
        if ($runtime !== []) {
            $this->config->set('auth.guards', array_merge($authGuards, $runtime));
        }

        return $merged;
    }

    /**
     * Builds the all() entry of a database guard.
     *
     * @return array<string, mixed>
     */
    private function databaseEntry(Guard $row): array
    {
        return [
            'id' => $row->id,
            'driver' => $row->driver,
            'provider' => $row->provider,
            'is_db' => true,
        ];
    }

    /**
     * True when an auth.guards entry carries the driver and provider of the given database row,
     * which is how a database guard injected at boot looks.
     *
     * @param  array<string, mixed>  $entry
     */
    private function entryMatchesRow(array $entry, Guard $row): bool
    {
        return ($entry['driver'] ?? null) === $row->driver
            && (($entry['provider'] ?? null) ?: null) === ($row->provider ?: null);
    }

    /**
     * Returns guard names only.
     *
     * @return array<int, string>
     */
    public function names(): array
    {
        return array_keys($this->all());
    }

    /**
     * Returns true if the given guard is managed by Clearance.
     */
    public function has(string $guard): bool
    {
        return in_array($guard, $this->names(), strict: true);
    }

    /**
     * Creates a new DB-managed guard.
     *
     * @throws ClearanceProtectedResourceException if the name is already a guard in auth.guards or the provider is unknown.
     */
    public function create(string $name, string $driver, ?string $provider = null): Guard
    {
        $name = strtolower($name);

        if (array_key_exists($name, (array) $this->config->get('auth.guards', []))) {
            throw new ClearanceProtectedResourceException(
                "Guard [{$name}] already exists in auth.guards and cannot be redefined."
            );
        }

        $this->assertProviderExists($provider);

        return Guard::create([
            'name' => $name,
            'driver' => $driver,
            'provider' => $provider ?: null,
        ]);
    }

    /**
     * Updates driver/provider on an existing guard (name is immutable).
     *
     * A change of driver or provider is refused, for every actor, while any role or permission exists on the guard.
     * A save that leaves both unchanged stays allowed.
     *
     * @throws ClearanceProtectedResourceException if the guard name shadows a config guard, the provider is unknown, or the guard is in use and driver or provider changes.
     */
    public function update(Guard $guard, string $driver, ?string $provider = null): Guard
    {
        $authGuards = (array) $this->config->get('auth.guards', []);

        // A name present in auth.guards with other values than the stored row is a config guard.
        if (array_key_exists($guard->name, $authGuards) && ! $this->entryMatchesRow((array) $authGuards[$guard->name], $guard)) {
            throw new ClearanceProtectedResourceException(
                "Guard [{$guard->name}] is defined in auth.guards and cannot be redefined."
            );
        }

        $this->assertProviderExists($provider);

        if (($driver !== $guard->driver || ($provider ?: null) !== ($guard->provider ?: null)) && $this->isInUse($guard)) {
            throw new ClearanceProtectedResourceException(
                "Guard [{$guard->name}] has roles or permissions: its driver and provider cannot be changed."
            );
        }

        $guard->update([
            'driver' => $driver,
            'provider' => $provider ?: null,
        ]);

        return $guard->fresh();
    }

    /**
     * Refuses a non-null provider that is not defined in auth.providers.
     *
     * @throws ClearanceProtectedResourceException
     */
    private function assertProviderExists(?string $provider): void
    {
        if ($provider === null || $provider === '') {
            return;
        }

        if (! array_key_exists($provider, (array) $this->config->get('auth.providers', []))) {
            throw new ClearanceProtectedResourceException(
                "Provider [{$provider}] is not defined in auth.providers."
            );
        }
    }

    /**
     * Whether any role or permission exists on the guard.
     */
    private function isInUse(Guard $guard): bool
    {
        return Role::where('guard_name', $guard->name)->exists()
            || Permission::where('guard_name', $guard->name)->exists();
    }

    /**
     * Deletes a DB-managed guard.
     *
     * @throws ClearanceProtectedResourceException if any role or permission exists on the guard, for every actor.
     */
    public function delete(Guard $guard): void
    {
        if ($this->isInUse($guard)) {
            throw new ClearanceProtectedResourceException(
                "Guard [{$guard->name}] has roles or permissions and cannot be deleted."
            );
        }

        $guard->delete();
    }
}
