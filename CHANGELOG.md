# Changelog

All notable changes to `rivalex/clearance` will be documented in this file.
Format follows [Conventional Commits](https://conventionalcommits.org).

---

## [Unreleased] — 2026-04-27

### T16 — feat(livewire): UserRoleManager optional panel — server-side manager scope (V4,V8,I.Users)

**Files created:**
- `src/Livewire/Users/UserRoleManager.php`
- `resources/views/livewire/users/user-role-manager.blade.php`
- `tests/Unit/T16UserRoleManagerTest.php`

**Files modified:**
- `routes/web.php` — `/users` route wired to `UserRoleManager::class` (only when `modules.users = true`)

**What and why:** Implements the optional `modules.users` panel for contextual user-role assignment. The component has two modes: admin mode (full view of all `UserRoleContext` records) and manager mode (scoped to the manager's own context). Mode is determined server-side in `resolveManagerScope()` which queries `UserRoleContext` for the authenticated user — if a record exists, that context becomes the scope (V4). Manager mode cannot assign or revoke outside their own `context_type`+`context_id` — enforced in both `assign()` and `revoke()` with explicit error messages. All writes target `UserRoleContext` (Clearance-owned table) via `firstOrCreate` and `delete()` — no Spatie model methods called (V8). `availableRoles` is loaded from Spatie's `Role` model (read-only query) but no writes to Spatie tables occur. Route is only registered when `config('clearance.modules.users', false)` is truthy.

---

### T15 — feat(livewire): HierarchyManager panel (V2,V3,V9,V8,I.Hierarchy)

**Files created:**
- `src/Livewire/Hierarchy/HierarchyManager.php`
- `resources/views/livewire/hierarchy/hierarchy-manager.blade.php`
- `tests/Unit/T15HierarchyManagerTest.php`

**Files modified:**
- `routes/web.php` — `/hierarchy` route wired to `HierarchyManager::class`
- `CHANGELOG.md` — backfill T1–T15

**What and why:** Implements the hierarchy management panel that enforces the single-level parent→child role constraint (V3). All write operations (createRelation, deleteRelation, addOverride, removeOverride) route through `HierarchyService`, which throws `ClearanceHierarchyViolationException` on V3 violations and `ClearanceInvalidOverrideException` on V2 violations. The component surfaces an override drill-down per hierarchy entry showing forced_on/forced_off overrides with per-permission badges (emerald = forced_on, red = forced_off). Orphan role badges identify roles that have no hierarchy relationships. V9 auto-cleanup (forced_on overrides deleted when parent loses permission) is handled entirely in HierarchyService::deleteRelation — the UI just calls the service. No direct Spatie model calls in component (V8).

---

### T14 — feat(livewire): RoleManager + RoleForm (V8,I.Roles)

**Files created:**
- `src/Livewire/Roles/RoleManager.php`
- `src/Livewire/Roles/RoleForm.php`
- `resources/views/livewire/roles/role-manager.blade.php`
- `resources/views/livewire/roles/role-form.blade.php`
- `tests/Unit/T14RoleManagerTest.php`

**Files modified:**
- `routes/web.php` — `/roles` route wired to `RoleManager::class`

**What and why:** `RoleManager` lists all Spatie roles enriched with `RoleMeta` data (is_system, is_protected). Protected roles have the Delete button suppressed at UI level. `RoleForm` provides create/edit with guard-scoped permission checkboxes loaded from `Permission::where('guard_name', $guard)`. All Spatie writes (create role, rename, syncPermissions) go through `RoleService` (V8). `RoleMeta` badge flags (is_system, is_protected) are persisted via `RoleMeta::updateOrCreate` — this is a Clearance-owned table, not a Spatie call, so V8 is not violated. Guard change triggers `updatedGuardName()` which reloads the permission list for the new guard.

---

### T13 — feat(livewire): PermissionManager + PermissionForm (V6,V8,I.Permissions)

**Files created:**
- `src/Livewire/Permissions/PermissionManager.php`
- `src/Livewire/Permissions/PermissionForm.php`
- `resources/views/livewire/permissions/permission-manager.blade.php`
- `resources/views/livewire/permissions/permission-form.blade.php`
- `tests/Unit/T13PermissionManagerTest.php`

**Files modified:**
- `routes/web.php` — `/permissions` route wired to `PermissionManager::class`

**What and why:** Full CRUD for Spatie permissions routed through `PermissionService` (V8). `PermissionManager` uses `colorForGroup(string $group): string` — a deterministic `crc32` hash mapped to a 10-color Tailwind palette — so all permissions sharing the same group prefix always display the same badge color. Copy-to-clipboard is a vanilla JS `navigator.clipboard.writeText()` inline onclick, requiring no additional dependencies. `PermissionForm` catches `ClearanceNamingException` from `PermissionService::validate()` and surfaces the error inline (V6). The `permission-saved` Livewire event is dispatched on save or cancel to let `PermissionManager` refresh its list and hide the form.

---

### T12 — feat(livewire): GuardManager read-only screen (V8,I.Guards)

**Files created:**
- `src/Livewire/Guards/GuardManager.php`
- `resources/views/livewire/guards/guard-manager.blade.php`
- `tests/Unit/T12GuardManagerTest.php`

**Files modified:**
- `routes/web.php` — `/guards` route wired to `GuardManager::class`; placeholder closures removed
- `tests/Feature/.gitkeep` — created to satisfy Pest `->in('Feature', 'Unit')` config

**What and why:** Guards are read-only config-derived data — no write operations exist. `GuardManager::mount()` injects `GuardService` and loads `all()` into a public property. The view renders a table of guard name/driver/provider. No Spatie calls anywhere (V8). The component is a full-page Livewire component using `#[Layout('clearance::layouts.app')]`.

---

### T11 — feat(views): clearance::layouts.app self-contained layout (I.routes)

**Files created:**
- `resources/views/layouts/app.blade.php`
- `tests/Unit/T11LayoutTest.php`

**What and why:** Panel layout must be self-contained — it cannot depend on the host application's layout (`@extends('layouts.app')` is explicitly absent). Tailwind 4 is loaded via the `@tailwindcss/browser@4` CDN script so no host app CSS pipeline is needed. Livewire scripts are loaded via standard `@livewireStyles` / `@livewireScripts` directives. Flux UI scripts are conditionally loaded only if `config('clearance.ui.flux_pro')` is truthy or `\Flux\Flux::pro()` returns true — avoiding errors when Flux is not installed. Uses `{{ $slot }}` for Livewire full-page component rendering.

---

### T10 — feat(blade): @canin/@endcanin Blade directives (V4,I.canin,I.ContextService)

**Files modified:**
- `src/ClearanceServiceProvider.php` — added `Blade::directive('canin', ...)` and `Blade::directive('endcanin', ...)` in `bootingPackage()`
- `tests/Unit/T10CaninDirectiveTest.php` — 3 compilation tests

**What and why:** `@canin($permission, $model)` compiles to `<?php if(app(\Rivalex\Clearance\Services\ContextService::class)->hasPermissionIn(auth()->user(), $permission, $model)): ?>`. Resolves `ContextService` from the IoC container on each directive invocation — no global state cached (V4). `@endcanin` compiles to `<?php endif; ?>`. The directive is registered at package boot, available in all Blade templates once the package is loaded.

---

### T9 — feat(commands): clearance:install Artisan command (V1,V10,I.install)

**Files created:**
- `src/Commands/ClearanceInstallCommand.php`
- `tests/Unit/T9InstallCommandTest.php`
- `tests/Feature/.gitkeep`

**Files modified:**
- `src/ClearanceServiceProvider.php` — `hasCommand(ClearanceInstallCommand::class)` + `runsMigrations()`

**What and why:** Install command is idempotent via a `.clearance-installed` marker file in `storage/` (V10). Second run without `--force` outputs a skip message and exits early. `--force` bypasses the marker. Publishes config and migrations via `vendor:publish`, then calls `artisan migrate` wrapped in try-catch (handles table-already-exists when developer re-runs after manual migration). Creates the `clearance-access` permission (or `config('clearance.access_permission')`) via `Permission::firstOrCreate`. `--user=ID` assigns the permission directly to a user; `--role=NAME` creates/finds the role and assigns via `PermissionService::assignToRole()` (V1 — ensures panel is accessible after install).

---

### T8 — feat(middleware): RequireClearanceAccess + routes (V1,I.middleware,I.routes)

**Files created:**
- `src/Http/Middleware/RequireClearanceAccess.php`
- `routes/web.php`
- `tests/Unit/T8MiddlewareTest.php`

**Files modified:**
- `src/ClearanceServiceProvider.php` — `aliasMiddleware('clearance.access', ...)` + `hasRoute('web')`

**What and why:** Middleware checks `$request->user()?->can($permission)` where `$permission` comes from `config('clearance.access_permission', 'clearance-access')`. Uses `can()` not `hasRole()` per V1. Returns HTTP 403 (via `abort(403)`) for unauthorized or unauthenticated users. Routes use configurable prefix (`config('clearance.route_prefix')`) and merge base middleware with `clearance.access` alias.

---

### T7 — feat(services): ContextService (V4,I.ContextService)

**Files created:**
- `src/Services/ContextService.php`
- `tests/Support/FakeUser.php`
- `tests/Support/FakeContext.php`
- `tests/Unit/T7ContextServiceTest.php`

**What and why:** `resolveFor($user, $model)` looks up `UserRoleContext` by `user_id`, `context_type` (class name of model), and `context_id` (model PK). Returns the permissions of the matched role merged with any `RolePermissionOverride` entries (forced_on adds, forced_off removes). Server-side scope enforcement: query always filters by all three keys — no data from other contexts leaks (V4). `hasPermissionIn($user, $permission, $model)` is a convenience wrapper over `resolveFor`.

---

### T6 — feat(services): HierarchyService (V2,V3,V9,I.HierarchyService)

**Files created:**
- `src/Services/HierarchyService.php`
- `src/Exceptions/ClearanceHierarchyViolationException.php`
- `src/Exceptions/ClearanceInvalidOverrideException.php`
- `tests/Unit/T6HierarchyServiceTest.php`

**What and why:** `createRelation($parent, $child)` throws `ClearanceHierarchyViolationException` if parent is already a child of any role, or child is already a parent of any role (V3 — single-level). `addOverride($hierarchy, $permission, 'forced_on')` throws `ClearanceInvalidOverrideException` if the parent role does not have the permission (V2). `deleteRelation()` calls `cleanupForcedOnOverrides()` which deletes all `forced_on` overrides referencing permissions the parent role no longer has (V9). `removeOverride()` deletes a single override.

---

### T5 — feat(models): RoleMeta, RoleHierarchy, RolePermissionOverride, UserRoleContext (V5,I.migrations)

**Files created:**
- `src/Models/RoleMeta.php`
- `src/Models/RoleHierarchy.php`
- `src/Models/RolePermissionOverride.php`
- `src/Models/UserRoleContext.php`
- `tests/Unit/T5ModelsTest.php`

**What and why:** Four Clearance-owned Eloquent models that extend Spatie's schema without touching it (V5). `RoleMeta` stores `is_system` and `is_protected` booleans per Spatie role. `RoleHierarchy` stores `parent_role_id`/`child_role_id` FK pairs with cascade deletes. `RolePermissionOverride` stores `override_type` enum (`forced_on`/`forced_off`) per hierarchy+permission pair. `UserRoleContext` stores `user_id`, `context_type`, `context_id`, `role_id` for contextual role assignments. All FKs reference `roles`/`permissions` tables with `ON DELETE CASCADE`.

---

### T4 — feat(services): RoleService (V8,I.PermissionService)

**Files created:**
- `src/Services/RoleService.php`
- `tests/Unit/T4RoleServiceTest.php`

**What and why:** `create(name, guardName)`, `rename(role, name)`, `delete(role)`, `syncPermissions(role, permissionNames[])`. `syncPermissions` resolves each permission name via `Permission::where('name', ...)->where('guard_name', $role->guard_name)`, rejects permissions from different guards (guard-scoped enforcement), then calls Spatie's `$role->syncPermissions()`. Livewire components must not call these directly — they inject `RoleService` (V8).

---

### T3 — feat(services): PermissionService (V6,V8,I.PermissionService)

**Files created:**
- `src/Services/PermissionService.php`
- `src/Exceptions/ClearanceNamingException.php`
- `tests/Unit/T3PermissionServiceTest.php`

**What and why:** `validate(string $name)` enforces `gruppo-azione` format via regex `/^[a-z][a-z0-9]*([-][a-z][a-z0-9]*)+$/` (V6). Throws `ClearanceNamingException` on violation. `create`, `rename`, `delete`, `assignToRole`, `revokeFromRole` are the only paths that mutate the `permissions` and `role_has_permissions` tables (V8). `groupFor(name)` extracts the prefix before the separator.

---

### T2 — feat(services): GuardService (I.config,I.Guards)

**Files created:**
- `src/Services/GuardService.php`
- `tests/Unit/T2GuardServiceTest.php`

**What and why:** `all()` returns guard configs from `config('auth.guards')` filtered to those listed in `config('clearance.guards')` when the override is non-empty, otherwise returns all. `has(guardName)` checks membership. Injected into Livewire components and install command so they always use the configured guard set.

---

### T1 — feat(scaffold): ClearanceServiceProvider, config, 4 migrations (V5,I.config,I.migrations)

**Files created:**
- `src/ClearanceServiceProvider.php`
- `config/clearance.php`
- `database/migrations/create_clearance_role_meta_table.php.stub`
- `database/migrations/create_clearance_role_hierarchy_table.php.stub`
- `database/migrations/create_clearance_role_permission_overrides_table.php.stub`
- `database/migrations/create_clearance_user_role_contexts_table.php.stub`
- `composer.json`
- `tests/TestCase.php`
- `tests/Pest.php`
- `tests/Unit/T1SchemaTest.php`

**What and why:** Package foundation. `ClearanceServiceProvider` extends `PackageServiceProvider` from `spatie/laravel-package-tools`. All 4 migration stubs use `.stub` extension (package convention) and create only `clearance_*` tables — Spatie core tables (`roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions`) are never touched (V5). Config keys cover all §I.config surface. Test infrastructure uses Orchestra Testbench with a `runMigrations()` helper that directly includes `.stub` files (bypassing `artisan migrate`) because Testbench cannot auto-discover `.stub` extension migrations.
