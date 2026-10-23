<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Support;

/**
 * Single source of the identity guard and the user model.
 *
 * Authentication middleware such as `auth:sanctum` rewrites `auth.defaults.guard` for the rest of
 * the request, so the default guard is captured once when the package boots and every identity
 * decision reads it from here instead of from the live config.
 */
final class Identity
{
    /** The default guard captured at boot, null until captured. */
    private static ?string $bootGuard = null;

    /**
     * Captures the current default guard. Called once while the package boots, before any middleware runs.
     */
    public static function captureBootGuard(): void
    {
        self::$bootGuard = (string) config('auth.defaults.guard', 'web');
    }

    /**
     * Forgets the captured guard (for tests).
     */
    public static function flush(): void
    {
        self::$bootGuard = null;
    }

    /**
     * The guard on which identity decisions are made: `clearance.guard` when set, otherwise the
     * default guard captured at boot (the live default guard only when nothing was captured yet).
     */
    public static function guard(): string
    {
        $configured = config('clearance.guard');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return self::$bootGuard ?? (string) config('auth.defaults.guard', 'web');
    }

    /**
     * The user model class: `clearance.user_model` when set, otherwise the model of the provider
     * of the identity guard. Null when it cannot be resolved. The class is not checked for existence.
     */
    public static function userModel(): ?string
    {
        $configured = config('clearance.user_model');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $provider = config('auth.guards.'.self::guard().'.provider');

        if (! is_string($provider) || $provider === '') {
            return null;
        }

        $model = config('auth.providers.'.$provider.'.model');

        return is_string($model) && $model !== '' ? $model : null;
    }
}
