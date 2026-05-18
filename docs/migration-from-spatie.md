# Migrating from spatie/laravel-permission to Clearance

Clearance is built **on top of** spatie/laravel-permission — it requires `^6.0` and extends Spatie's `Role` and `Permission` models. Migration is additive: existing roles, permissions, and assignments are preserved. No Spatie tables are dropped or truncated.

---

## How it works

Clearance adds 6 `clr_*` tables alongside the Spatie schema:

| Table | Purpose |
|---|---|
| `clr_meta` | Display metadata (display name, icon, color) for roles and guards |
| `clr_role_meta` | Per-role flags: `is_locked`, `scope`, `context_types` |
| `clr_guards` | Guards managed via the Clearance UI |
| `clr_settings` | Runtime key/value settings |
| `clr_role_ctx` | Contextual role assignments (user + context type + context ID) |
| `clr_ctx_overrides` | Per-user forced permission overrides within a context |

All foreign keys in `clr_*` tables point to `roles.id` and `permissions.id` with `ON DELETE CASCADE`. No data loss on role/permission removal.

---

## Pre-flight checklist

Before running `clearance:install` on a production database:

- [ ] Backup the database (minimum: `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions`)
- [ ] Note any role named `super_admin`, `superadmin`, `super-admin`, `admin`, `root`, or `owner` — see [Super Admin collision](#super-admin-collision) below
- [ ] Check whether `config/permission.php` overrides `models.role` or `models.permission` — see [Custom model subclasses](#custom-model-subclasses) below
- [ ] Note custom guards defined in `config/auth.php` — see [Custom guards](#custom-guards) below

---

## Installation

```bash
composer require rivalex/clearance
php artisan clearance:install
```

`clearance:install` detects an existing Spatie installation (`Schema::hasTable('roles')`), skips Spatie's migrations, and only creates the `clr_*` tables.

---

## Super Admin collision

> **Warning:** This is the only step that can silently alter existing data.

`clearance:install` calls `Role::firstOrCreate(['name' => 'super_admin', ...])` then **`syncPermissions()`** on it. `syncPermissions()` is destructive — it replaces all existing permissions on that role with only the `clearance-*` permissions.

**If you already have a `super_admin` role with application permissions, those permissions will be removed.**

Because `super_admin_gate_bypass` defaults to `true` in `config/clearance.php`, the role still passes all `can()` checks via `Gate::before()`, so the removal may go unnoticed — until the bypass is disabled.

### How to handle it

**Option A — your role is already named `super_admin`**

Run install, then manually re-add your application permissions:

```bash
php artisan clearance:install
```

```php
// In a post-install migration or seeder:
$role = Role::findByName('super_admin');
$role->givePermissionTo(['users-manage', 'billing-view', /* ... */]);
```

**Option B — your super admin role has a different name** (e.g. `admin`, `superadmin`)

Clearance creates a new `super_admin` role alongside the existing one. Two options:

1. Rename your existing role to `super_admin` before installing (recommended for clean migration).
2. Keep both and assign `super_admin` only to users who also manage the Clearance panel.

**Option C — no `super_admin` role wanted**

Set `super_admin_gate_bypass` to `false` in `config/clearance.php` after install, then delete the `super_admin` role. Clearance will enforce normal `can()` checks for all users.

---

## Custom model subclasses

If `config/permission.php` overrides the default Spatie models:

```php
// config/permission.php
'models' => [
    'role'       => App\Models\Role::class,
    'permission' => App\Models\Permission::class,
],
```

Clearance does **not** override these bindings. Spatie will continue using your custom models for `$user->roles`, `$user->permissions`, etc.

However, the Clearance Livewire components instantiate `Rivalex\Clearance\Models\Role` and `Rivalex\Clearance\Models\Permission` directly for create/edit operations. This creates a split: Spatie returns your subclass, Clearance writes via its own class.

**Recommendation**: if your subclasses add accessors, casts, or event listeners relevant to Clearance's create/edit flow, extend Clearance's models instead of Spatie's:

```php
// App\Models\Role — extend Clearance instead of Spatie
use Rivalex\Clearance\Models\Role as ClearanceRole;

class Role extends ClearanceRole
{
    // your customisations
}
```

Then update `config/permission.php` to point to the new subclass.

> If your subclasses are thin (only query scopes or accessors not used in Clearance forms), the split is harmless — no schema or FK impact.

---

## Custom guards

Guards defined statically in `config/auth.php` are **not** automatically imported into `clr_guards`. They continue to work in Laravel normally, but will not appear in the Clearance Guards panel until registered there.

To import existing guards after install, seed them directly:

```php
use Rivalex\Clearance\Models\Guard;

Guard::firstOrCreate(['name' => 'api',     'driver' => 'token',   'provider' => 'users']);
Guard::firstOrCreate(['name' => 'sanctum', 'driver' => 'sanctum', 'provider' => 'users']);
```

Or add them via the UI at `/clearance/guards`.

Only drivers listed in `config/clearance.php` → `allowed_guard_drivers` are injected into `auth.guards` at boot. Default: `session`, `token`, `jwt`, `passport`, `sanctum`.

---

## Backfilling display metadata

After install, all existing roles and permissions appear in the Clearance panel without display names, icons, or colors — those are stored in `clr_meta` and `clr_role_meta`, which start empty.

Populate via the UI (edit each role/permission individually), or seed programmatically:

```php
use Rivalex\Clearance\Models\ClearanceMeta;
use Rivalex\Clearance\Models\RoleMeta;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Str;

Role::all()->each(function (Role $role) {
    ClearanceMeta::firstOrCreate(
        ['subject_type' => 'role', 'subject_key' => $role->name],
        ['display_name' => Str::headline($role->name)],
    );
    RoleMeta::firstOrCreate(
        ['role_id' => $role->id],
        ['scope' => 'global', 'is_locked' => false],
    );
});
```

> A dedicated `clearance:backfill` artisan command is planned. Until then, use the snippet above in a seeder.

---

## Rollback

To remove Clearance without affecting Spatie data:

```bash
php artisan migrate:rollback --path=database/migrations
composer remove rivalex/clearance
```

The `clr_*` tables are dropped. Spatie tables (`roles`, `permissions`, `model_has_*`, `role_has_permissions`) are untouched.

---

## Known limitations

| Limitation | Status |
|---|---|
| No automated `clearance:backfill` command | Planned |
| `syncPermissions()` on `super_admin` during install is destructive | Known issue — manual re-add via `givePermissionTo()` required |
| Static `config/auth.php` guards not auto-imported | Manual seed required |
| No detection of equivalent super admin roles (`admin`, `superadmin`, etc.) | Manual rename recommended pre-install |
