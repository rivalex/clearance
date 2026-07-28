<div align="center">

<a href="https://rivalex.github.io/clearance-docs/" target="_blank" style="margin: 30px 0 50px !important;">
<figure>
<img src="resources/images/clearance-mark-horizontal.svg" alt="Clearance" width="500" height="auto">
</figure>
</a>

---

### **A complete permissions, roles & contextual authorization panel for Laravel**

[![Latest Version on Packagist](https://img.shields.io/packagist/v/rivalex/clearance.svg)](https://packagist.org/packages/rivalex/clearance)
[![PHP Version](https://img.shields.io/badge/PHP-8.3%2B-blue)](https://php.net)
[![Laravel Version](https://img.shields.io/badge/Laravel-12--13-orange)](https://laravel.com)
[![License](https://img.shields.io/github/license/rivalex/clearance)](LICENSE.md)
[![codecov](https://codecov.io/github/rivalex/clearance/branch/master/graph/badge.svg?token=ogc9M1neEf)](https://codecov.io/github/rivalex/clearance)
[![Tests](https://github.com/rivalex/clearance/actions/workflows/run-tests.yml/badge.svg)](https://github.com/rivalex/clearance/actions/workflows/run-tests.yml)

A drop-in admin panel for Laravel applications built on top of [spatie/laravel-permission](https://github.com/spatie/laravel-permission). Adds a Livewire 4 + Flux UI panel with contextual authorization and role ceilings - installable with one artisan command, without altering any Spatie table.

</div>

---

<div align="center">

## [Official Documentation](https://rivalex.github.io/clearance-docs/)

</div>

---

## What Clearance gives you

- **Admin panel** for Permissions, Roles, Guards, Settings and a Dashboard - use the pre-built routes or embed each Livewire component individually.
- **Contextual authorization** - grant a role or permission scoped to a specific model instance (a Store, a Tenant, a Project), with `$user->canIn()`, `@canin`/`@hasrolein` Blade directives, and a `ContextService` for use outside the User model.
- **Ceiling roles** - a role can declare a parent role whose permission set acts as an upper bound; a child role can never exceed its parent, enforced automatically on every save.
- **Fine-grained write permissions** - one delegable `clearance-{section}-write` permission per panel section, so you can hand out "manage roles" without also handing out "manage settings."
- **Super Admin** with an opt-in global Gate bypass, alias detection for existing admin-like roles, and safe non-destructive promotion.
- **Users module** (opt-in) - assign roles globally or to a specific context, plus per-context permission overrides, from a per-user panel.
- **Settings panel** - per-role/guard display name, description, sanitized SVG icon and color; a configurable default role with auto-assign and bulk-assign.
- **Guards management** - database-backed guards, injected into `auth.guards` at boot, restricted to an allow-listed set of drivers.
- **`clearance:install`** - one command sets up Spatie (if missing), migrations, permissions, and the `super_admin` role.
- **`clearance:backfill`** - adopt Clearance into an app that already has Spatie roles/permissions.
- **9 bundled languages** (ar, en, es, fr, hi, it, pt, ru, zh), key-parity tested.
- **Extensibility hooks** - `HasClearance` trait, `HasPermissionGroups` trait, `ClearanceContextable` contract, reusable Blade components.
- **SVG sanitization** - every user-supplied icon passes through an allow-list sanitizer before storage and at render time.
- **Security-hardened** - privilege-escalation ceilings on every permission-granting path, deny-authoritative context overrides, protected `clearance-*` namespace. See [Security](#security).
- **370 tests, 1054 assertions** - Pest suite covering commands, services, Livewire components, contextual authorization, ceiling roles, and i18n key-parity.

## Requirements

| Dependency | Version |
|---|---|
| PHP | ^8.3 |
| Laravel | ^12.0 \| ^13.0 |
| spatie/laravel-permission | ^6.0 |
| livewire/livewire | ^3.0 \| ^4.0 |
| livewire/flux | ^2.14 |

## Installation

```bash
composer require rivalex/clearance
```

Run the installer:

```bash
php artisan clearance:install
```

> **Always run `clearance:install`, never a plain `php artisan migrate` on a fresh setup.** Clearance's own migrations depend on Spatie's `roles`/`permissions` tables existing first; the installer publishes and runs Spatie's migrations before its own. If you migrate directly and Spatie isn't set up yet, you'll get an actionable error message telling you to run the installer instead.

Optional flags:

| Flag | Description |
|---|---|
| `--user=ID` | Assign the `super_admin` role to a user after install |
| `--role=NAME` | Assign `clearance-access` to a role (created if absent) |
| `--super-admin-role=NAME` | Promote an existing role to `super_admin` (skips interactive prompt) |
| `--force` | Re-run even if already installed |

What the installer does:
1. Publishes `config/clearance.php`.
2. Publishes Clearance's migrations.
3. Detects Spatie Permission tables - if the `roles` table is absent, publishes and runs Spatie's migrations automatically first.
4. Runs all pending migrations.
5. Creates `clearance-access` and all 5 fine-grained capability permissions: `clearance-permissions-write`, `clearance-roles-write`, `clearance-guards-write`, `clearance-settings-write`, `clearance-users-write`.
6. Creates the `super_admin` role with all `clearance-*` permissions assigned (additive - never removes existing permissions on re-run).
7. Writes `storage/.clearance-installed` as an idempotency marker.

> **Migrating from an existing Spatie install?** Use `php artisan clearance:backfill` (see [Migrating from an existing Spatie install](#migrating-from-an-existing-spatie-install)) and read [docs/migration-from-spatie.md](docs/migration-from-spatie.md) first - important notes on `super_admin` collision, custom model subclasses, and guard import.

---

## ⚠️ Required: add `HasClearance` to your User model

To use contextual authorization methods (`$user->canIn()`, `$user->hasRoleIn()`), add the `HasClearance` trait to `App\Models\User`. It already includes Spatie's `HasRoles` - **one line replaces both**:

```php
use Rivalex\Clearance\Traits\HasClearance;

class User extends Authenticatable
{
    use HasClearance; // includes HasRoles + all contextual authorization methods
}
```

> **Already using `use HasRoles`?** Replace it with `use HasClearance`, or keep both - PHP deduplicates trait composition automatically, no conflicts.

Without this trait, `$user->canIn()` and related methods are unavailable, but the Blade directives (`@canin`, `@hasrolein`) and `ContextService` keep working regardless (they don't depend on the trait).

---

## Quick Start

### Option A - Pre-built routes

After installation the panel is available at `/clearance` (or the configured `route_prefix`). All routes are protected by the `clearance.access` middleware and use the host app's Livewire layout automatically.

| URI | Route name | Description |
|---|---|---|
| `/clearance` | `clearance.home` | Dashboard |
| `/clearance/guards` | `clearance.guards` | Guard management (create/edit/delete) |
| `/clearance/permissions` | `clearance.permissions` | Permission CRUD with group-prefix naming validation |
| `/clearance/roles` | `clearance.roles` | Role CRUD with guard-scoped permission assignment, scope (global/contextual), and ceiling |
| `/clearance/settings` | `clearance.settings` | Display metadata (icons, colors), default role, general settings |
| `/clearance/user/{userId}` | `clearance.user` | Per-user role assignment + contextual permission overrides (requires `modules.users = true`) |

Route names stay stable regardless of prefix changes. To customize middleware:

```php
// config/clearance.php
'middleware' => ['web', 'auth', 'verified'],
```

### Option B - Embedded Livewire components

Each panel section can be mounted individually inside any Blade view, Filament page, or custom route:

```blade
<livewire:clearance::dashboard />
<livewire:clearance::permissions.permission-manager />
<livewire:clearance::roles.role-manager />
<livewire:clearance::guards.guard-manager />
<livewire:clearance::settings.settings-manager />
{{-- Users module (requires modules.users = true in config) --}}
<livewire:clearance::users.user-clearance-manager :userId="$user->id" />
```

---

## Contextual Authorization

Clearance extends Spatie's global authorization model with **context-scoped** checks. A user's roles can be assigned within a specific context (e.g. a Store, Tenant, or Project) and are invisible outside that scope.

### On the User model

```php
// Does the user have this permission in this context?
$user->canIn('orders-create', $store);

// Alias - mirrors Spatie's hasPermissionTo() naming
$user->hasPermissionIn('orders-create', $store);

// Does the user hold this role in this context?
$user->hasRoleIn('manager', $store);

// Optionally filter by guard
$user->canIn('orders-create', $store, 'api');
$user->hasRoleIn('manager', $store, 'web');
```

### In Blade views

```blade
@canin('orders-create', $store)
    <button>New Order</button>
@endcanin

@hasrolein('manager', $store)
    <a href="{{ route('reports') }}">Reports</a>
@endhasrolein
```

Both directives resolve safely (return `false`) for a guest user - they never throw.

### Via ContextService (advanced)

For use in controllers, policies, or jobs where the User model may not have the trait:

```php
use Rivalex\Clearance\Services\ContextService;

$service = app(ContextService::class);

$service->canIn($user, 'orders-create', $store);
$service->hasPermissionIn($user, 'orders-create', $store); // alias
$service->hasRoleIn($user, 'manager', $store);
$service->resolveFor($user, $store); // Collection of all effective permission names
```

Or via the `Clearance` facade, which delegates to the same service:

```php
Clearance::canIn($user, 'orders-create', $store);
Clearance::resolveFor($user, $store);
Clearance::guards(); // names of all managed authentication guards
```

### How resolution works

`resolveFor()` merges three sources, in this precedence order:

1. **Contextual role grants** - permissions from roles the user holds specifically within this context (`clr_role_ctx`).
2. **Global role grants** - permissions from the user's globally-assigned Spatie roles (so `super_admin` and other global roles satisfy `canIn()` for any context).
3. **Per-context overrides** (`clr_ctx_overrides`) - `forced_on` adds a permission on top of the above; `forced_off` **removes it, even if the permission was granted by a global role**. A deny always wins.

---

## Super Admin

The `super_admin` role is created automatically by `clearance:install` and kept in sync with all `clearance-*` permissions.

```bash
# Install and make user ID 1 a super admin
php artisan clearance:install --user=1
```

### Gate bypass (opt-in)

`super_admin_gate_bypass` controls whether the `super_admin` role bypasses **all** Laravel `can()` / `Gate::allows()` / policy checks globally, via a `Gate::before()` hook.

> **Default: `false`.** The bypass is opt-in. To enable it:

```php
// config/clearance.php
'super_admin_gate_bypass' => true,
```

When enabled, any user holding `super_admin` skips all Gate checks **application-wide** - not only Clearance panel checks. For a narrower scope, leave it off and grant permissions explicitly.

### Alias detection

If your database already contains a role named `super_admin`, `superadmin`, `super-admin`, `root`, or `owner`, the installer detects it and prompts you interactively to promote it or create a separate `super_admin`. Use `--super-admin-role=NAME` to skip the prompt:

```bash
php artisan clearance:install --super-admin-role=owner
```

Promotion renames the role in place - all existing `model_has_roles` assignments are preserved via the FK on `role_id`.

---

## Panel Access & Fine-grained write permissions

Navigate to `/clearance` after installation. Read access to the whole panel requires the `clearance-access` permission (checked via `can()`, never `hasRole()`):

```php
$user->givePermissionTo('clearance-access');
```

Write operations on each section are gated by a dedicated, delegable permission - all created by `clearance:install` and assigned to `super_admin`:

| Permission | Controls |
|---|---|
| `clearance-permissions-write` | Create / edit / delete permissions |
| `clearance-roles-write` | Create / edit / delete roles and their permission assignments |
| `clearance-guards-write` | Create / edit / delete guards |
| `clearance-settings-write` | Edit display metadata, icons, colors, default role |
| `clearance-users-write` | Assign / remove roles for a specific user |

```php
// Grant a user write access to roles only:
$user->givePermissionTo('clearance-access');
$user->givePermissionTo('clearance-roles-write');
```

If a fine-grained permission row doesn't exist at all in the database (a legacy pre-seeding state), the check falls back to `clearance-access` for backwards compatibility. On any instance that has run `clearance:install`, this fallback never activates.

`clearance-*` permissions are reserved: they cannot be created, renamed, deleted, or added to any role except through the package's own install flow - even by a `super_admin` actor for the `super_admin` role itself.

---

## Roles

### Scope: global vs. contextual

Every role declares a scope at creation time:
- **`global`** - assigned directly to a user via `$user->assignRole()`, applies everywhere.
- **`contextual`** - bound to one or more model types (`context_types`), assignable only within a specific context instance via `UserRoleContext`.

Roles without an explicit `RoleMeta` row default to `global` for backwards compatibility.

### Ceiling roles

A role can declare a **ceiling** - a parent role whose permission set acts as an upper bound:

```php
use Rivalex\Clearance\Services\RoleService;

app(RoleService::class)->setParent($childRole, $parentRole);
```

- The child can only ever hold permissions the parent also holds. Any excess is **silently trimmed** on `setParent()` and on every subsequent permission sync - no exception, no error message, just enforced silently.
- A role that already acts as a parent cannot become a child (`ClearanceScopeViolationException`).
- A role that is already a child cannot itself act as a parent (`ClearanceScopeViolationException`).
- Removing permissions from a parent cascades the removal to every child automatically.
- Setting a permissive parent grants the child nothing by itself - the ceiling only **trims**, it never **grants**.

This is configured per-role in the Roles panel (`RoleForm`), or programmatically via `RoleService::setParent()` / `removeParent()`.

---

## Guards

Guards can be managed from the panel and are injected into `auth.guards` at application boot, so they're recognized by Laravel's auth system before any request runs. Only drivers in the allow-list are accepted:

```php
// config/clearance.php
'allowed_guard_drivers' => ['session', 'token', 'jwt', 'passport', 'sanctum'],
```

A guard with an unlisted driver is skipped at boot (with a log warning) rather than corrupting the auth config.

---

## Users module (opt-in)

Disabled by default. Enable it in `config/clearance.php`:

```php
'modules' => ['users' => true],
```

Navigate to `/clearance/user/{userId}` to manage a specific user's roles and permissions. Access requires `clearance-access` (read) and `clearance-users-write` (assign/remove).

### What the panel does

- **Global role assignment** - assign `scope=global` roles directly to the user, application-wide.
- **Contextual role assignment** - assign `scope=contextual` roles scoped to a specific model instance (a Store, Team, or Project declared in `contextual_models`).
- **Per-context permission overrides** - for each contextual role assignment, additional permissions can be force-granted or force-denied independently per context instance.
- **Remove assignment** - removes a role from the user (global or contextual scope), cascading the deletion of any associated overrides.

Both role assignment and direct/override permission grants enforce a privilege ceiling: a non-`super_admin` actor cannot modify their own assignments, and cannot grant a permission they don't themselves hold.

### Linking to the panel from your app

```php
route('clearance.user', ['userId' => $user->id])
```

### Contextual roles under the hood

```php
use Rivalex\Clearance\Models\UserRoleContext;

// Samuele is "StoreStaff" only within Alessandro's Store
UserRoleContext::create([
    'user_id'      => $samuele->id,
    'role_id'      => $staffRole->id,
    'context_type' => Store::class,
    'context_id'   => $store->id,
]);
```

Authorization checks respect the context via `$user->canIn()`, `@canin`, and `ContextService`.

---

## Settings

The Settings panel (`clearance-settings-write` to edit) lets you configure:

- **Display metadata** per role/guard - display name, description, and a sanitized SVG icon + hex color, shown throughout the panel.
- **Default role** - the role automatically assigned to newly registered users when `auto_assign_default_role` is `true`.
- **Bulk-assign default role** - one-click assignment of the current default role to every existing user who doesn't already have it.
- **Show icons** - toggles icon display across the panel.

```php
// config/clearance.php
'auto_assign_default_role' => true, // requires a default role set in Settings
```

---

## Dashboard

The Dashboard shows at-a-glance stats: total roles, guards, and permissions; the number of distinct permission groups (by naming prefix); the number of contextual role assignments; and the top 5 roles ranked by user count.

---

## Permission Naming Convention

Permissions must follow the `group-action` format:

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

The separator itself (`-` or `_`) is validated at boot - it's the only piece of this config that reaches a raw SQL fragment (grouping/sorting permissions), so only those two characters are accepted.

---

## Configuration

Publish the config:

```bash
php artisan vendor:publish --tag=clearance-config
```

`config/clearance.php` reference:

| Key | Default | Purpose |
|---|---|---|
| `route_prefix` | `'clearance'` | URI prefix for all panel routes. |
| `middleware` | `['web', 'auth']` | Applied to all panel routes before `clearance.access`. |
| `access_permission` | `'clearance-access'` | Permission checked by the access middleware. |
| `user_model` | `null` | Defaults to `auth.providers.users.model`. |
| `modules.users` | `false` | Master switch for the Users module (routes + Livewire components). |
| `contextual_models` | `[]` | `FQCN => ['label', 'icon', 'label_attribute', 'searchable']` - models that can act as role-binding contexts. |
| `enforce_naming_convention` | `true` | Enforce `group-action` permission naming. |
| `naming_separator` | `'-'` | Separator character - `'-'` or `'_'` only. |
| `guards` | `[]` | Override auto-detected guards from `config/auth.php`. Empty = auto-detect all. |
| `allowed_guard_drivers` | `['session', 'token', 'jwt', 'passport', 'sanctum']` | Drivers accepted when injecting DB guards into `auth.guards`. |
| `layout` | `null` | Blade layout for full-page components. `null` = host app's default (`config('livewire.component_layout')`). |
| `auto_assign_default_role` | `false` | Auto-assign the Settings-configured default role on `Registered` event. |
| `super_admin_gate_bypass` | `false` | Opt-in: `super_admin` bypasses all Gate checks application-wide. |
| `ui.flux_pro` | `null` | `null` = auto-detect via `Flux::pro()`. |
| `dark_mode.force` | `env('CLEARANCE_FORCE_DARK_MODE', false)` | Always render Clearance's dark theme regardless of the host app or OS color scheme. Overridable live from the Settings UI (Appearance card), which takes precedence over this default. |

---

## Appearance

Clearance's own CSS uses a class-based `dark:` variant (`&:where(.dark, .dark *)`), so it normally follows the host app's `<html class="dark">` ancestor - the same convention as Tailwind's default class strategy. If your host app has no class-based dark toggle (or its state doesn't match the real OS/browser preference), Clearance's own styling can render unstyled even though the rest of the page looks fine.

```php
// config/clearance.php
'dark_mode' => [
    'force' => env('CLEARANCE_FORCE_DARK_MODE', false),
],
```

Set `force` to `true` (or `CLEARANCE_FORCE_DARK_MODE=true`) to always render Clearance's dark theme, regardless of the host app or OS color scheme - useful when embedding Clearance in a host with no dark-mode toggle, or while developing locally on a light-mode system. Can also be toggled live from the Appearance card in `/clearance/settings`, which takes precedence over this config default.

---

## Database

Clearance owns 6 tables and never alters Spatie's core tables (`roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions`). All FKs referencing `roles.id`/`permissions.id` cascade on delete unless noted; Spatie tables have no FKs pointing back to `clr_*`.

| Table | Key columns |
|---|---|
| `clr_meta` | `subject_type`, `subject_key` (unique together) · `display_name`, `description`, `icon_svg`, `color` - display metadata for a role or guard |
| `clr_role_meta` | `role_id` (unique, FK → `roles`) · `is_locked`, `scope` (`global`\|`contextual`), `context_types` (json) · `parent_role_id` (nullable FK → `roles`, `ON DELETE SET NULL`) - ceiling role |
| `clr_guards` | `name` (unique), `driver`, `provider` - guards managed via the panel |
| `clr_settings` | `key` (unique), `value` - runtime key/value store (default role, etc.) |
| `clr_role_ctx` | `user_id`, `role_id` (FK → `roles`), `context_type`, `context_id` - contextual role assignments, unique per `(user, role, context_type, context_id)` |
| `clr_ctx_overrides` | `user_id`, `role_id` (FK → `roles`), `permission_id` (FK → `permissions`), `context_type`, `context_id`, `type` (`forced_on`\|`forced_off`) - per-user, per-context permission overrides |

---

## Extending the package

- **`HasClearance` trait** (`Rivalex\Clearance\Traits\HasClearance`) - adds `canIn()`, `hasPermissionIn()`, `hasRoleIn()` to your User model; includes Spatie's `HasRoles`.
- **`HasPermissionGroups` trait** (`Rivalex\Clearance\Concerns\HasPermissionGroups`) - if you configure a custom `Permission` model (`config('permission.models.permission')`) instead of Clearance's own, add this trait to keep the grouped-UI accessors (`permission_group`, `group_string`, `abilities()`, `colorForAbility()`) working:

```php
use Rivalex\Clearance\Concerns\HasPermissionGroups;

class Permission extends \Spatie\Permission\Models\Permission
{
    use HasPermissionGroups;
}
```

- **`ClearanceContextable` contract** (`Rivalex\Clearance\Contracts\ClearanceContextable`) - implement `clearanceLabel(): string` on a context model for a custom display label in the panel; otherwise `contextual_models.<FQCN>.label_attribute` (default `name`) is used.
- **Reusable Blade components** - registered under the `clearance::` namespace, usable in your own views: `x-clearance::message`, `x-clearance::clipboard`, `x-clearance::validation-errors`, plus a small icon set (`x-clearance::icon.*`). No external JS dependency beyond Alpine (already required by Livewire).

---

## i18n

9 languages ship out of the box: `ar`, `en`, `es`, `fr`, `hi`, `it`, `pt`, `ru`, `zh` (`resources/lang/{locale}/ui.php`). All locales expose an identical key set, verified by a dedicated test on every change.

To add or override a language, publish the translation files:

```bash
php artisan vendor:publish --tag=clearance-translations
```

This copies them to `lang/vendor/clearance/{locale}/ui.php` in your app. Add a new locale directory there, using `en/ui.php` as the canonical key reference.

---

## Migrating from an existing Spatie install

If your application already uses `spatie/laravel-permission` with existing roles, permissions, and assignments, `clearance:install` detects and preserves them - it never runs Spatie's migrations if the `roles` table already exists.

Backfill Clearance-specific metadata for pre-existing Spatie data:

```bash
php artisan clearance:backfill              # meta + role scope + guards
php artisan clearance:backfill --only=meta  # just one section
php artisan clearance:backfill --dry-run    # preview, no writes
```

- `meta` - seeds a `clr_meta` display name (title-cased role name) for every role without one.
- `roles` - seeds `clr_role_meta` defaults (`scope=global`, `is_locked=false`) for every role without one.
- `guards` - imports guards from `config('auth.guards')` into `clr_guards`, filtered by `allowed_guard_drivers`.

All sections are idempotent. Full guide, including `super_admin` collision handling and custom model subclass notes: [docs/migration-from-spatie.md](docs/migration-from-spatie.md).

---

## Security

Clearance went through a dedicated pre-publication security audit plus two follow-up hardening passes before this release. Highlights: privilege-escalation ceilings on every permission-granting path (direct grants and role-level syncing alike), deny-authoritative context overrides, an allow-list SVG sanitizer, a protected `clearance-*` permission namespace, and guest-safe Blade directives.

Full audit trail, findings, and fixes: [`docs/plans/security-audit/plan.md`](docs/plans/security-audit/plan.md).

Found a vulnerability? Please report it privately to the maintainer rather than opening a public issue.

---

## Tips & Gotchas

- **Always run `clearance:install`**, not a plain `migrate`, on a fresh setup - Clearance's migrations depend on Spatie's tables existing first.
- **Add `HasClearance` to your User model** to unlock `$user->canIn()`/`hasRoleIn()`; the Blade directives and `ContextService` work without it.
- **`forced_off` always wins** - even over a permission granted by a global role. Use it deliberately.
- **A ceiling role only trims, never grants.** Setting a permissive parent role doesn't hand the child anything on its own - the child still needs those permissions explicitly assigned.
- **The Gate bypass is application-wide, not panel-scoped.** Turning on `super_admin_gate_bypass` affects every `can()`/`Gate::allows()`/policy check in your app.
- **The Users module is off by default.** Set `modules.users => true` to expose it.
- **`clearance-*` permissions are untouchable outside the install flow** - by design, so the panel's own access control can't be tampered with from inside the panel.

## Testing

370 tests, 1054 assertions - covering commands, services, Livewire components, contextual authorization, ceiling roles, and i18n key-parity across all 9 bundled languages.

```bash
vendor/bin/pest
vendor/bin/pest --coverage
```

## License

MIT - see [LICENSE.md](LICENSE.md).
