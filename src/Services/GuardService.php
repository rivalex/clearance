<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Services;

use Illuminate\Contracts\Config\Repository;
use Rivalex\Clearance\Models\Guard;

class GuardService
{
    public function __construct(
        private readonly Repository $config,
    ) {}

    /**
     * Returns all guards to manage, keyed by guard name.
     * Merges guards from auth.guards, clearance.guards override, and clearance_guards table.
     *
     * @return array<string, array<string, string>>
     */
    public function all(): array
    {
        $authGuards = $this->config->get('auth.guards', []);
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
            $dbGuards = Guard::all()->keyBy('name')->map(fn ($g) => [
                'id'       => $g->id,
                'driver'   => $g->driver,
                'provider' => $g->provider,
                'is_db'    => true,
            ])->toArray();

            $merged = array_merge($base, $dbGuards);
            
            // Also ensure auth.guards is updated at runtime so Laravel knows about them
            $this->config->set('auth.guards', array_merge($authGuards, $dbGuards));

            return $merged;
        } catch (\Exception $e) {
            // Migration might not be run yet
            return $base;
        }
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
     */
    public function create(string $name, string $driver, ?string $provider = null): Guard
    {
        return Guard::create([
            'name'     => strtolower($name),
            'driver'   => $driver,
            'provider' => $provider ?: null,
        ]);
    }

    /**
     * Updates driver/provider on an existing guard (name is immutable).
     */
    public function update(Guard $guard, string $driver, ?string $provider = null): Guard
    {
        $guard->update([
            'driver'   => $driver,
            'provider' => $provider ?: null,
        ]);

        return $guard->fresh();
    }

    /**
     * Deletes a DB-managed guard.
     */
    public function delete(Guard $guard): void
    {
        $guard->delete();
    }
}
