# Clearance

A drop-in permission/role management panel for Laravel applications built on top of [spatie/laravel-permission](https://github.com/spatie/laravel-permission). Adds a Livewire 4 + Flux UI admin panel with contextual authorization and role hierarchy — without altering any Spatie tables.

## Requirements

| Dependency | Version |
|---|---|
| PHP | ^8.4 |
| Laravel | ^11.0 \| ^12.0 \| ^13.0 |
| spatie/laravel-permission | ^6.0 |
| livewire/livewire | ^3.0 |

## Installation

```bash
composer require rivalex/clearance
```

Run the installer:

```bash
php artisan clearance:install
```

Optional flags:

| Flag | Description |
|---|---|
| `--user=ID` | Assign `clearance-access` permission directly to a user |
| `--role=NAME` | Assign `clearance-access` to a role (created if absent) |
| `--force` | Re-run even if already installed |

The installer:
1. Publishes `config/clearance.php`
2. Publishes and runs 4 migrations
3. Creates the `clearance-access` permission
4. Writes `storage/.clearance-installed` as an idempotency marker

## Configuration

Publish the config:

```bash
php artisan vendor:publish --tag=clearance-config
```

`config/clearance.php` keys:

```php
return [
    'route_prefix'              => 'clearance',
    'middleware'                => ['web', 'auth'],
    'access_permission'         => 'clearance-access',
    'user_model'                => null,          // defaults to auth.providers.users.model
    'enforce_naming_convention' => true,
    'naming_separator'          => '-',
    'guards'                    => [],            // extra guards beyond auth.guards
    'modules' => [
        'users'     => false,   // optional UserRoleManager panel
        'hierarchy' => true,
    ],
    'ui' => [
        'flux_pro' => false,    // auto-detected; set true to force Flux Pro components
    ],
];
```

## Panel Access

Navigate to `/clearance` after installation. Access requires the `clearance-access` permission (checked via `can()`, never `hasRole()`).

Available screens:

| Route | Description |
|---|---|
| `/clearance/guards` | Read-only list of configured guards |
| `/clearance/permissions` | Full permission CRUD with naming validation |
| `/clearance/roles` | Role CRUD with guard-scoped permission assignment |
| `/clearance/hierarchy` | Parent→child role hierarchy with override drill-down |
| `/clearance/users` | Optional: contextual user→role assignment (`modules.users = true`) |

## Permission Naming Convention

Permissions must follow the `gruppo-azione` format:

```
orders-create       ✓
orders-read         ✓
store-orders-delete ✓

create              ✗  (bare action, no group)
orders.create       ✗  (dot separator)
Orders-Create       ✗  (camelCase)
```

Disable enforcement per environment:

```php
'enforce_naming_convention' => false,
```

## Blade Directive — `@canin`

Check contextual permissions in views:

```blade
@canin('orders-create', $store)
    <button>New Order</button>
@endcanin
```

`@canin($permission, $model)` resolves permissions for the authenticated user within the given model's context via `ContextService::hasPermissionIn()` — no global state mutation.

## Role Hierarchy

Single-level parent→child role hierarchies. A role can be either a parent or a child, never both.

**Overrides** allow per-child customization:

- `forced_on` — grants a permission to the child even if not directly assigned (parent must possess the permission)
- `forced_off` — removes a permission from the child even if directly assigned

Override cleanup is automatic: when a parent role loses a permission, all `forced_on` overrides for that permission on its children are deleted.

## Contextual Roles (`modules.users`)

Enable the users module to assign roles scoped to a specific context (e.g., a Store, Tenant, or Team):

```php
'modules' => ['users' => true],
```

The `UserRoleManager` component supports two modes:
- **Admin mode** — full view of all `UserRoleContext` records
- **Manager mode** — scoped to the manager's own context (enforced server-side)

## Database

Clearance owns 4 tables and never alters Spatie's core tables:

| Table | Purpose |
|---|---|
| `clearance_role_meta` | Extra metadata per Spatie role |
| `clearance_role_hierarchy` | Parent→child relationships |
| `clearance_role_permission_overrides` | `forced_on`/`forced_off` overrides |
| `clearance_user_role_contexts` | User→role assignments scoped to a context model |

## Testing

```bash
vendor/bin/pest
vendor/bin/pest --coverage
```

## License

MIT — see [LICENSE.md](LICENSE.md).
