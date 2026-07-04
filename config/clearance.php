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
    | 'users' - master switch: gates the /clearance/users routes AND
    |           registers the Users Livewire components. Set to true to
    |           expose the user management panel.
    */
    'modules' => [
        'users' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Contextual Models
    |--------------------------------------------------------------------------
    | Models that can act as role-binding contexts (e.g. Store, Team, Project).
    | Each entry: FQCN => ['label' => '...', 'label_attribute' => 'name', 'searchable' => ['name']].
    | The model may optionally implement \Rivalex\Clearance\Contracts\ClearanceContextable
    | for custom label rendering; otherwise label_attribute is used as fallback.
    */
    'contextual_models' => [
        // App\Models\Store::class => [
        //     'label'           => 'Store',
        //     'icon'            => 'building-storefront',
        //     'label_attribute' => 'name',
        //     'searchable'      => ['name'],
        // ],
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
    | Allowed Guard Drivers
    |--------------------------------------------------------------------------
    | Only guards with one of these drivers are injected into auth.guards at
    | boot. Prevents auth subsystem corruption from invalid driver values.
    */
    'allowed_guard_drivers' => ['session', 'token', 'jwt', 'passport', 'sanctum'],

    /*
    |--------------------------------------------------------------------------
    | Layout
    |--------------------------------------------------------------------------
    | Blade layout used by Clearance full-page Livewire components.
    | null = use the host app's Livewire default (config('livewire.component_layout'),
    |         which defaults to 'components.layouts.app').
    | Set to a view name to override, e.g. 'layouts.app'.
    */
    'layout' => null,

    /*
    |--------------------------------------------------------------------------
    | Auto-assign Default Role
    |--------------------------------------------------------------------------
    | When true, Clearance listens to Illuminate\Auth\Events\Registered and
    | automatically assigns the default role (stored in clearance_settings)
    | to every newly registered user.
    | Set to false to handle role assignment manually in your application.
    */
    'auto_assign_default_role' => false,

    /*
    |--------------------------------------------------------------------------
    | Super Admin Gate Bypass
    |--------------------------------------------------------------------------
    | When true, a Gate::before() hook grants all permissions to users
    | holding the 'super_admin' role, bypassing every policy/can() check.
    | Set to false to enforce normal permission checks for super admins too.
    */
    'super_admin_gate_bypass' => false,

    /*
    |--------------------------------------------------------------------------
    | UI
    |--------------------------------------------------------------------------
    */
    'ui' => [
        'flux_pro' => null, // null = auto-detect via Flux::pro()
    ],
];
