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
    | Identity Guard
    |--------------------------------------------------------------------------
    | The guard that decides who is super_admin, which model is the contextual
    | subject and where the panel write permissions live. Pinned so that
    | `auth:<guard>` route middleware cannot move it during a request.
    | Set to null to use the default guard (auth.defaults.guard) as it is when
    | the application boots. Set it when the panel users authenticate on a
    | guard other than that default.
    | A guard without a user provider (Sanctum) needs `clearance.user_model`;
    | otherwise leave this null. With `contextual_models` set, the application
    | refuses to boot when no user model resolves.
    */
    'guard' => null,

    /*
    |--------------------------------------------------------------------------
    | User Scope
    |--------------------------------------------------------------------------
    | Optional class name implementing \Rivalex\Clearance\Contracts\ClearanceUserScope.
    | When set, every users-panel component asks it whether the signed-in actor may
    | open and edit a given user, on mount and on every write action. super_admin is
    | never restricted. Leave null for single-tenant hosts (no restriction).
    | Multi-tenant hosts should set it: without it a scoped manager can open the
    | panel of any user id and read that user's name and email.
    */
    'user_scope' => null,

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
    | Non-delegable permissions
    |--------------------------------------------------------------------------
    | Permissions that no actor can grant through a user-facing path, super_admin
    | included. Exact names (`team-invite`) or group patterns ending in `*`
    | (`billing-*`, or `billing_*` when `naming_separator` is `_`); the part before
    | `*` must end with the separator. A role containing one of them is
    | system-assigned only: the panel cannot assign it, only trusted code can
    | (assignContextual() / assignGlobalRole()). Revocation is never restricted.
    */
    'non_delegable' => [],

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
    | Memoize Contextual Resolution
    |--------------------------------------------------------------------------
    | When true, ContextService::resolveFor() (and therefore canIn() and the
    | @canin directive) keeps its result for the rest of the request, so
    | repeated checks for the same user, context and guard run no query.
    | The memo is flushed by every Clearance service write and by the
    | UserRoleContext / UserContextPermissionOverride model events.
    | It is NOT flushed by direct Spatie writes in the same request: hosts
    | that assign roles through Spatie directly and re-check in the same
    | request must keep this off.
    */
    'memoize_resolution' => false,

    /*
    |--------------------------------------------------------------------------
    | Panel Role Holder Limit
    |--------------------------------------------------------------------------
    | The Roles page refuses to delete a role whose global holders plus
    | contextual bindings exceed this number: removing or reassigning them
    | costs several queries per holder in one request. Delete such a role
    | from the console with `php artisan clearance:delete-role`.
    */
    'panel_role_holder_limit' => 1000,

    /*
    |--------------------------------------------------------------------------
    | UI
    |--------------------------------------------------------------------------
    */
    'ui' => [
        'flux_pro' => null, // null = auto-detect via Flux::pro()
    ],
];
