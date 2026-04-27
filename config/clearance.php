<?php

declare(strict_types=1);

// config for Rivalex/Clearance
return [

    /*
    |--------------------------------------------------------------------------
    | Route Prefix
    |--------------------------------------------------------------------------
    | The URI prefix for all Clearance panel routes.
    */
    'route_prefix' => 'clearance',

    /*
    |--------------------------------------------------------------------------
    | Middleware
    |--------------------------------------------------------------------------
    | Applied to all Clearance routes before RequireClearanceAccess.
    */
    'middleware' => ['web', 'auth'],

    /*
    |--------------------------------------------------------------------------
    | Access Permission
    |--------------------------------------------------------------------------
    | The permission checked by RequireClearanceAccess middleware.
    | Must follow naming convention: gruppo-azione.
    */
    'access_permission' => 'clearance-access',

    /*
    |--------------------------------------------------------------------------
    | User Model
    |--------------------------------------------------------------------------
    | Set to null for auto-detection via auth.providers config.
    */
    'user_model' => null,

    /*
    |--------------------------------------------------------------------------
    | Modules
    |--------------------------------------------------------------------------
    | Toggle optional Clearance modules.
    */
    'modules' => [
        'users' => false,
        'hierarchy' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Naming Convention
    |--------------------------------------------------------------------------
    | Enforce gruppo-azione format for all permission names.
    */
    'enforce_naming_convention' => true,
    'naming_separator' => '-',

    /*
    |--------------------------------------------------------------------------
    | Guards
    |--------------------------------------------------------------------------
    | Override auto-detected guards from config/auth.php.
    | Empty array = auto-detect all guards.
    */
    'guards' => [],

    /*
    |--------------------------------------------------------------------------
    | UI
    |--------------------------------------------------------------------------
    */
    'ui' => [
        'flux_pro' => null, // null = auto-detect via Flux::pro()
    ],

];
