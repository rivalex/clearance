<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Services;

use Illuminate\Contracts\Config\Repository;

class GuardService
{
    public function __construct(
        private readonly Repository $config,
    ) {}

    /**
     * Returns all guards to manage, keyed by guard name.
     * Uses clearance.guards override if set; otherwise auto-detects from auth.guards.
     *
     * @return array<string, array<string, string>>
     */
    public function all(): array
    {
        $authGuards = $this->config->get('auth.guards', []);
        $override   = $this->config->get('clearance.guards', []);

        if (! empty($override)) {
            return array_filter(
                $authGuards,
                static fn (string $key): bool => in_array($key, $override, strict: true),
                ARRAY_FILTER_USE_KEY,
            );
        }

        return $authGuards;
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
}
