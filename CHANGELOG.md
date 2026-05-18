# Changelog

All notable changes to `rivalex/clearance` will be documented in this file.
Format follows [Conventional Commits](https://conventionalcommits.org).

---

## [Unreleased] - 2026-05-18 — Security patch 6

### security: 2 additional vulnerabilities resolved (M1, L1)

**Files modified:**
- `resources/views/livewire/settings/guard-meta-table.blade.php` — `{!! icon_svg !!}` wrapped with `SvgSanitizer::sanitize()`; both `style=` colour attrs use `safeCssColor()` (M1, L1)
- `resources/views/livewire/settings/role-meta-table.blade.php` — same (M1, L1)
- `resources/views/livewire/guards/guard-manager.blade.php` — `{!! icon_svg !!}` wrapped with `SvgSanitizer::sanitize()` (M1)
- `resources/views/livewire/roles/role-manager.blade.php` — same (M1)
- `resources/views/livewire/settings.blade.php` — `{!! $meta->icon_svg !!}` (×2) wrapped with `SvgSanitizer::sanitize()`; preview `style=` colour uses `safeCssColor()` (M1, L1)

**Verified**: 222/222 tests pass.

---

## [Unreleased] - 2026-05-18 — Security patch 5

### security: 6 additional vulnerabilities resolved (M1–M3, L1–L3)

**Files modified:**
- `src/Livewire/Settings.php` — `#[Locked]` on `metaSubjectType` + `metaSubjectKey`; `saveMeta()` now validates subject type allowlist + full `max:` rules (M1, M2)
- `src/Support/SvgSanitizer.php` — `safeCssColor()` static helper; `cleanNode()` strips `DOMComment` nodes (M3, L3)
- `resources/views/livewire/settings.blade.php` — all `style=` colour injections use `SvgSanitizer::safeCssColor()` (M3)
- `resources/views/livewire/guards/guard-manager.blade.php` — same (M3)
- `resources/views/livewire/roles/role-manager.blade.php` — same (M3)
- `src/Livewire/Guards/NewGuard.php` — `render()` upgraded from `canAccess()` to `canPerform('guards')` (L1)
- `src/Livewire/Permissions/NewPermission.php` — `render()` upgraded to `canPerform('permissions')` (L1)
- `src/Livewire/Roles/NewRole.php` — `render()` upgraded to `canPerform('roles')` (L1)
- `src/Livewire/Permissions/PermissionGroupRow.php` — `abort_unless(canAccess())` added to `render()` (L2)

**Verified**: 222/222 tests pass.

---

## [Unreleased] - 2026-05-18 — Security patch 4

### security: defense-in-depth render guards for all container/display components

**Root cause**: Container Livewire components (managers, dashboards, New* wrappers) relied solely on route middleware for access control. If embedded outside the protected route, they expose DB data without any component-level auth check.

**New method**: `Clearance::canAccess()` — checks basic `clearance-access` permission (with CLI test bypass). Distinct from `canPerform()` which checks write-level permissions. Used in all read-only render paths.

**Files modified:**
- `src/Clearance.php` — added `canAccess()` method for read-level panel access check
- `src/Livewire/Dashboard.php` — `abort_unless(canAccess())` in `render()`
- `src/Livewire/Guards/GuardManager.php` — `abort_unless(canAccess())` in `render()`
- `src/Livewire/Guards/NewGuard.php` — `abort_unless(canAccess())` in `render()`
- `src/Livewire/Permissions/PermissionManager.php` — `abort_unless(canAccess())` in `render()`
- `src/Livewire/Permissions/NewPermission.php` — `abort_unless(canAccess())` in `render()`
- `src/Livewire/Roles/RoleManager.php` — `abort_unless(canAccess())` in `render()`
- `src/Livewire/Roles/NewRole.php` — `abort_unless(canAccess())` in `render()`
- `src/Livewire/Settings/SettingsManager.php` — `abort_unless(canAccess())` in `render()`

**Verified**: `composer audit` — no known CVEs. 222/222 tests pass.

---

## [Unreleased] - 2026-05-18 — Security patch 3

### security: 8 additional vulnerabilities resolved (H1–H2, M1–M3, L1)

**Files modified:**
- `src/Livewire/Settings.php` — `render()` passes `$metaIconSvgPreview` via `SvgSanitizer::sanitize()` for live preview in monolith view (H1)
- `resources/views/livewire/settings.blade.php` — preview uses `{!! $metaIconSvgPreview !!}` not raw `{!! $metaIconSvg !!}` (H1)
- `resources/views/livewire/permissions/permission-form.blade.php` — `e($editingPrefix)` added inside `{!! !!}` block (H2)
- `src/Livewire/Users/UserClearanceManager.php` — `canPerform('users')` guard added to `mount()` (M1)
- `src/Livewire/Settings/GuardMetaTable.php` — `canPerform('settings')` guard added to `render()` (M2)
- `src/Livewire/Settings/RoleMetaTable.php` — `canPerform('settings')` guard added to `render()` (M2)
- `src/Livewire/Settings/GeneralSettings.php` — role existence check added before persisting `$defaultRole` (M3)
- `src/Support/SvgSanitizer.php` — `cleanAttributes()` now called on root `<svg>` element, not only children (L1)

---

## [Unreleased] - 2026-05-18 — Security patch 2

### security: 14 additional vulnerabilities resolved (H-1–H-3, M-1–M-5, L-2, I-1)

**Files modified:**
- `src/ClearanceServiceProvider.php` — `super_admin_gate_bypass` fallback default corrected `true→false` (H-3)
- `src/Livewire/Settings.php` — `SvgSanitizer::sanitize()` on `saveMeta()`; `canPerform('settings')` guards on all 3 write methods (H-1, M-3)
- `src/Livewire/Settings/EditMeta.php` — `render()` passes `$iconSvgPreview` via `SvgSanitizer::sanitize()` (H-2)
- `resources/views/livewire/settings/edit-meta.blade.php` — preview uses `{!! $iconSvgPreview !!}` not raw `{!! $iconSvg !!}` (H-2)
- `src/Livewire/Users/AssignRoleModal.php` — `contextClass` validated against `contextual_models` allowlist in `save()` + `render()` (M-1)
- `src/Livewire/Users/RemoveAssignmentModal.php` — same allowlist check in `confirmDelete()` (M-1)
- `src/Livewire/Permissions/PermissionForm.php` — clearance prefix guard normalized to use `naming_separator` (M-2)
- `src/Support/SvgSanitizer.php` — `behavior:` and `binding:` CSS XSS vectors added to style regex (M-4)
- `src/Livewire/Dashboard.php` — `groups_count` uses `INSTR()` on SQLite, `POSITION()` on MySQL (M-5)
- `src/Livewire/Roles/RoleForm.php` — `rules()` added; `$this->validate()` enforced in `save()` (L-2)
- `src/Models/Role.php` — **deleted**: leaked application-level model with wrong namespace (I-1)

---

## [Unreleased] - 2026-05-18 — Security patch

### security: 15 vulnerabilities resolved (C1, H1–H5, M1–M5, L1–L4)

**Files modified:**
- `src/Livewire/Permissions/PermissionForm.php` — `#[Locked]` on `$editingPrefix` (H3)
- `src/Livewire/Permissions/PermissionManager.php` — separator re-validation + search max-100 (C1, L1)
- `resources/views/livewire/permissions/delete-permission.blade.php` — `e($prefix)` in `{!! !!}` (H1)
- `src/Livewire/Guards/GuardForm.php` — `regex:/^[a-z0-9_\-]+$/` + `max:64` on name (M3)
- `resources/views/livewire/guards/delete-guard.blade.php` — `e($name)` in `{!! !!}` (H1)
- `src/Livewire/Roles/DeleteRole.php` — typed-confirm moved before DB mutations (M5)
- `src/Livewire/Roles/RoleForm.php` — guard-based permission ID whitelist in `save()` (L2)
- `src/Livewire/Users/AssignRoleModal.php` — contextClass whitelist + self-escalation guard (M2, H5)
- `src/Livewire/Users/ContextualRolesPanel.php` — contextClass whitelist in `mount()` (M2)
- `src/Livewire/Dashboard.php` — separator re-validation at call site (C1)
- `src/Support/SvgSanitizer.php` — CSS XSS guard on `style` attribute values (H2)
- `src/Clearance.php` — `PHP_SAPI === 'cli'` scopes test bypass to CLI only (M1)
- `src/Commands/ClearanceInstallCommand.php` — seeds all 5 fine-grained permissions (L3)
- `config/clearance.php` — `super_admin_gate_bypass` defaults to `false` (L4)

**Breaking change:** `super_admin_gate_bypass` now defaults to `false`. Installations that rely on the Gate bypass must explicitly set `'super_admin_gate_bypass' => true` in their published `config/clearance.php`.

---

## [Unreleased] - 2026-05-16 (patch 3)

### feat(concerns): `HasPermissionGroups` trait for customer Permission subclasses

**Files (created):** `src/Concerns/HasPermissionGroups.php`
**Files (modified):** `src/Models/Permission.php`, `src/ClearanceServiceProvider.php`, `docs/migration-from-spatie.md`

**Problem solved.** Customers who configure `config/permission.php → models.permission = App\Models\Permission::class` (extending SpatiePermission directly) had a split: their `$user->permissions` returned their subclass without Clearance's group-based UI accessors (`permission_group`, `group_string`, `abilities()`, `colorForAbility()`). The only options were to extend Clearance's Permission class (no documentation) or go without those accessors.

**Solution.** All logic previously inlined in `src/Models/Permission.php` is extracted into a standalone `HasPermissionGroups` trait (`src/Concerns/HasPermissionGroups.php`). Clearance's own Permission model now uses the trait — zero behavior change for the default case. Customers who want the group features in their own subclass add one line:

```php
use Rivalex\Clearance\Concerns\HasPermissionGroups;

class Permission extends SpatiePermission
{
    use HasPermissionGroups;
}
```

**Boot warning.** `ClearanceServiceProvider` now logs a `Log::warning` at boot if it detects a custom permission model configured via `config('permission.models.permission')` that does NOT use the `HasPermissionGroups` trait. Non-blocking, developer-visible, one-time at boot.

**Implementation detail.** The trait uses Eloquent's `initialize{TraitName}()` convention to merge `permission_group` and `group_string` into `$appends` at instance initialization, avoiding a PHP trait-property conflict with `Illuminate\Database\Eloquent\Model::$appends`.

---

## [Unreleased] - 2026-05-16 (patch 2)

### feat(install): non-destructive super_admin setup + alias detection + `--super-admin-role` flag

**Files (modified):**
- `src/Commands/ClearanceInstallCommand.php` — refactored `createSuperAdminRole()` into 5 focused private methods; replaced `syncPermissions()` with additive `givePermissionTo()` diff; added alias detection + interactive `choice()` prompt; added `--super-admin-role=NAME` option; `is_locked=true` now only set when role was freshly created (`wasRecentlyCreated`)
- `src/ClearanceServiceProvider.php` — registered `ClearanceBackfillCommand`

**Files (created):**
- `src/Commands/ClearanceBackfillCommand.php` — new `clearance:backfill` artisan command
- `docs/migration-from-spatie.md` — public migration guide shipped with the package
- `tests/Feature/T39MigrationFromSpatieTest.php` — 12 tests covering all migration scenarios

**Non-destructive install.** `createSuperAdminRole()` no longer calls `syncPermissions()`. Instead it computes the diff between existing permissions and missing `clearance-*` permissions and calls `givePermissionTo()` on the missing subset only. Pre-existing application permissions on `super_admin` are fully preserved.

**Alias detection.** Before creating `super_admin`, the installer scans existing roles for names matching `/^(super[_\-\s]?admin|root|owner)$/i`. If a candidate is found in interactive mode, the user is prompted to select it for promotion or create a separate `super_admin`. In non-interactive mode (CI), the installer warns and creates a separate `super_admin`.

**`--super-admin-role=NAME` flag.** Allows non-interactive promotion of a named role to `super_admin`. The role is renamed in-place (preserving all `model_has_roles` rows via FK on `role_id`) and receives clearance-* permissions additively.

**`is_locked` guard.** `RoleMeta::updateOrCreate(['is_locked' => true])` is called only when the super_admin role was created ex-novo (`wasRecentlyCreated`). Pre-existing roles retain their current `is_locked` value.

### feat(backfill): `clearance:backfill` command for Spatie migrations

**Files (created):** `src/Commands/ClearanceBackfillCommand.php`

**Options:** `--only=meta,roles,guards` (default: all three), `--dry-run` (preview without writing).

- `meta` — seeds `clr_meta` display_name (`Str::headline(name)`) for every Spatie role without an existing row
- `roles` — seeds `clr_role_meta` defaults (`scope=global`, `is_locked=false`) for every Spatie role without an existing row
- `guards` — imports guards from `config('auth.guards')` into `clr_guards`, filtered by `allowed_guard_drivers`; skips guards already present

All sections are idempotent via `firstOrCreate` semantics. `--dry-run` counts what would be inserted without touching the DB.

### docs(migration): migration guide for existing Spatie installations

**Files (created):** `docs/migration-from-spatie.md`

Complete developer guide: how Clearance extends Spatie (additive schema, zero FK conflicts), pre-flight checklist, super_admin alias handling (interactive prompt + `--super-admin-role` flag), custom model subclasses (two-binaries risk + recommended fix), guard import via backfill, metadata backfill snippet, rollback procedure. Known limitations table updated to reflect resolved issues.

---

## [Unreleased] - 2026-05-11

### feat(install): super_admin role + global Gate bypass

**Files (modified):**
- `config/clearance.php` - added `super_admin_gate_bypass` key (default `true`)
- `src/ClearanceServiceProvider.php` - registers `Gate::before()` bypass for `super_admin` when config enabled
- `src/Commands/ClearanceInstallCommand.php` - `createSuperAdminRole()` creates role and syncs all `clearance-*` permissions; `--user` now assigns `super_admin` role instead of direct permission; removed `assignToUser()`
- `tests/Unit/T9InstallCommandTest.php` - updated warning message; added test for `super_admin` role creation with permissions

**super_admin role.** `clearance:install` now creates a `super_admin` Spatie role and calls `syncPermissions()` with every permission whose name starts with `clearance-`. Safe to re-run (`firstOrCreate` + `syncPermissions` are idempotent).

**Gate bypass.** `ClearanceServiceProvider::bootingPackage()` registers a `Gate::before()` hook that returns `true` for any user holding the `super_admin` role - bypassing all `can()`, `Gate::allows()`, and policy checks globally. Controlled by `config('clearance.super_admin_gate_bypass', true)`. Set to `false` to disable.

**--user flag.** Previously assigned the `clearance-access` permission directly. Now assigns the `super_admin` role, which carries all clearance permissions and the Gate bypass.

---

## [Unreleased] - 2026-05-08

### feat(scope): M8 - role scope (global|contextual) + context_types binding + canIn 3-pass merge

**Files (new):**
- `src/Exceptions/ClearanceScopeViolationException.php`
- `tests/Unit/T37RoleScopeTest.php` (13 tests)

**Files (modified):**
- `database/migrations/create_clearance_role_meta_table.php.stub` - added `scope string(16) default 'global'`, `context_types json nullable`, index on `scope`
- `src/Models/RoleMeta.php` - constants `SCOPE_GLOBAL/SCOPE_CONTEXTUAL`, casts, helpers `isGlobal()`, `isContextual()`, `acceptsContext()`
- `src/Services/UserClearanceService.php` - `assignGlobalRole` rejects contextual roles; `assignContextual` rejects global roles and type mismatches
- `src/Services/ContextService.php` - `resolveFor()` refactored into `resolveContextual()` (pass 1+2) + `resolveGlobal()` (pass 3, global Spatie roles); V14 implemented
- `src/Livewire/Roles/RoleForm.php` - scope radio + context_types multi-select + persist to RoleMeta
- `src/Livewire/Users/GlobalRolesPanel.php` - filters availableMasterRoles to scope=global only
- `src/Livewire/Users/ContextualRolesPanel.php` - filters roles to scope=contextual + acceptsContext
- `src/Livewire/Users/AssignRoleModal.php` - catches ClearanceScopeViolationException → errorMessage
- `resources/views/livewire/roles/role-form.blade.php` - scope radio + context_types checkboxes UI
- `resources/lang/{ar,en,es,fr,hi,it,pt,ru,zh}/ui.php` - added `roles.form.scope`, `scope_global`, `scope_contextual`, `context_types`
- `SPEC.md` - §C constraints, §V V11-V15, §I I.canin, §T T27-T36s, §B B0
- `PRD.md` - §M8, §Appendice C (can vs canIn table)

**M8 - Role Scope.** Every role declares its scope at creation time: `global` (no context binding) or `contextual` (bound to specific model FQCN list). `UserClearanceService` enforces this via `ClearanceScopeViolationException`. Backward-compat: roles without `RoleMeta` default to `global` (V15).

**canIn() redesigned - 3-pass merge (V14).** Pass 1: contextual role permissions for the requested context. Pass 2: per-context overrides (forced_on/forced_off). Pass 3: permissions from user's globally-assigned Spatie roles. Super-admin assigned globally now satisfies `canIn()` for any context.

**Spatie can() invariant (V11).** `can()` remains global-only by design. For context-aware checks always use `canIn()`. Documented in PRD Appendice C.

---

## [Unreleased] - 2026-05-06

### refactor(hierarchy): drop Hierarchy module - to be redesigned from scratch

**Files deleted (12):** `src/Models/{RoleHierarchy,RolePermissionOverride}.php`, `src/Livewire/Hierarchy/HierarchyManager.php`, `src/Services/HierarchyService.php`, `src/Exceptions/{ClearanceHierarchyViolationException,ClearanceInvalidOverrideException}.php`, both migration stubs, both hierarchy views, `tests/Unit/{T6HierarchyServiceTest,T15HierarchyManagerTest}.php`

The single-level parent→child model with `forced_on`/`forced_off` overrides was architecturally misaligned: slave roles were blocked from direct assignment (making overrides unreachable), and overrides only applied in `ContextService::resolveFor()` but not in standard Spatie checks. Feature will be redesigned as a dedicated PRD starting from `canIn()` semantics.

**Also removed:** `/clearance/hierarchy` route, `modules.hierarchy` config key, hierarchy stats card from dashboard, `hierarchy` lang block from all 9 locales, `assertNotSlave()` from `UserClearanceService`, `RolePermissionOverride` second pass from `ContextService::resolveFor()`.

---

### feat(users): U1 - embeddable UserClearanceManager with contextual role/permission binding

**Files (new):** `src/Contracts/ClearanceContextable.php`, `src/Models/UserContextPermissionOverride.php`, `src/Services/UserClearanceService.php`, `database/migrations/create_clearance_user_context_permission_overrides_table.php.stub`, `src/Livewire/Users/{UserClearanceManager,GlobalRolesPanel,ContextualRolesPanel,AssignRoleModal,RemoveAssignmentModal}.php`, `resources/views/livewire/users/{manager,global-roles-panel,contextual-roles-panel,assign-role-modal,remove-assignment-modal,placeholder}.blade.php`, `tests/Unit/{T34UserClearanceServiceTest,T35UserClearanceManagerTest,T36ContextServiceOverridesTest}.php`

**Files (modified):** `src/Services/ContextService.php`, `src/Clearance.php`, `src/Commands/ClearanceInstallCommand.php`, `config/clearance.php`, `routes/web.php`, all 9 lang files (`user_permissions` → `user_clearance`)

**Files (deleted):** `src/Livewire/Users/UserPermissionManager.php`, `resources/views/livewire/users/user-permission-manager.blade.php`

**U1.1 - Embeddable component.** Old standalone-only `UserPermissionManager` replaced by `UserClearanceManager` (`#[Lazy]`). Drop in anywhere: `<livewire:clearance::users.manager :userId="$user->id" />`. The legacy `/clearance/user/{userId}` route stays but now renders the new component (gated by `config('clearance.modules.users')`).

**U1.2 - Global vs contextual role scope.** Each role assignment is either global (Spatie `assignRole`) or contextual (bound to one or more model instances via `UserRoleContext`). `GlobalRolesPanel` handles global scope; `ContextualRolesPanel` handles contextual, one section per model registered in `config('clearance.contextual_models')`.

**U1.3 - Per-context permission overrides (new table).** New migration `clearance_user_context_permission_overrides` stores `forced_on`/`forced_off` per `(user, role, permission, context)`. `ContextService::resolveFor()` extended with a third merge pass - most specific override wins. `HasClearance::canIn()` automatically reflects overrides.

**U1.4 - ClearanceContextable contract.** Host models may implement `Rivalex\Clearance\Contracts\ClearanceContextable::clearanceLabel(): string` for custom display labels. Fallback: `config('clearance.contextual_models.<FQCN>.label_attribute', 'name')`.

**U1.5 - Cascade on removal.** Removing a contextual role assignment automatically deletes all matching `UserContextPermissionOverride` rows.

**U1.6 - Slave-role guard.** `UserClearanceService::assertNotSlave()` blocks assignment of any role that is a `child_role_id` in role hierarchies - in both global and contextual scope.

**U1.7 - Service layer.** All mutations through `UserClearanceService` (7 methods). Authorization via `abort_unless(canPerform('users'), 403)` → `clearance-users-write` capability seeded by `clearance:install`.

**Tests:** 220/220 passing. New: T34 (6), T35 (8), T36 (4), T16 rewritten (6), T20 updated.

---

### security: Phase 1 - stored XSS, path traversal, driver injection, config validation, authorization layer

**Files:** `src/Support/SvgSanitizer.php` (new), `src/Exceptions/ClearanceConfigException.php` (new), `src/Clearance.php`, `src/ClearanceServiceProvider.php`, `src/Livewire/Settings.php`, `src/Livewire/Guards/GuardForm.php`, `src/Livewire/Permissions/PermissionForm.php`, `src/Livewire/Permissions/DeletePermission.php`, `src/Livewire/Permissions/EditPermission.php`, `src/Livewire/Roles/RoleForm.php`, `src/Livewire/Roles/DeleteRole.php`, `routes/web.php`, `config/clearance.php`, `resources/views/components/validation-errors.blade.php`, all 9 lang files

**S1 - Stored XSS via `icon_svg` (HIGH):** `SvgSanitizer::sanitize()` (DOMDocument, no new dependency) strips `<script>`, `on*` handlers, `<foreignObject>`, and non-fragment `href`. Wired into `Settings::saveMeta()` before DB write. Added `max:120/500/8000` validation for display_name, description, icon_svg.

**S2 - Path traversal on `/clearance/assets/{path}` (HIGH):** `realpath()` containment replaces naive `is_file()`. Route constraint narrowed to `[a-zA-Z0-9_\-./]+`. Unlisted extensions → 404 (no fallback `null` mime).

**S3 - Guard driver allowlist (HIGH):** `clearance.allowed_guard_drivers` config (default: session/token/jwt/passport/sanctum). `GuardForm::save()` validates against it. `injectDatabaseGuards()` skips invalid drivers + logs warning.

**S4 - `naming_separator` SQL injection surface (MEDIUM):** `bootingPackage()` validates separator matches `/^[\-_]$/`; throws `ClearanceConfigException` on violation.

**S5 - Locked wrapper properties (MEDIUM):** `#[Locked]` on `DeletePermission.$prefix/$guard/$groupKey` and `EditPermission.$prefix/$guard/$groupKey`. `validation-errors.blade.php`: `{!! $error !!}` → `{{ $error }}`.

**S6 - Authorization layer (MEDIUM):** `Clearance::canPerform(string $action): bool` checks `clearance-{action}-write` first, falls back to `clearance-access` (backwards-compatible). `abort_unless(canPerform(...), 403)` applied to all mutating Livewire methods across Permissions, Roles, Settings.

### feat(dashboard): G4 - Dashboard SVG icons extracted to reusable Blade components

**New files:** `resources/views/components/icon/shield-user.blade.php`, `group.blade.php`, `brick-wall-shield.blade.php`, `git-compare-arrows.blade.php`, `users.blade.php`, `settings.blade.php`

**Modified:** `resources/views/livewire/dashboard.blade.php`

- 6 inline SVGs replaced with `<x-clearance::icon.*>` anonymous components (registered via existing `Blade::anonymousComponentPath`)
- Users link in stats card and quick links now conditional on `config('clearance.modules.users')` (default false)
- Hierarchy quick link already had `config('clearance.modules.hierarchy')` guard - kept

### feat(settings): G3 - Settings split into focused sub-components

**Files deleted:** `src/Livewire/Settings.php`

**New PHP:** `src/Services/MetaService.php`, `src/Livewire/Settings/SettingsManager.php`, `GeneralSettings.php`, `BulkAssignDefaultRole.php`, `RoleMetaTable.php`, `GuardMetaTable.php`, `EditMeta.php`

**New views:** `resources/views/livewire/settings/manager.blade.php`, `general-settings.blade.php`, `bulk-assign-default-role.blade.php`, `role-meta-table.blade.php`, `guard-meta-table.blade.php`, `edit-meta.blade.php`

**Modified:** `routes/web.php` - settings route now points to `SettingsManager::class`

- **SettingsManager** - `#[Lazy]` top-level page, embeds 4 sub-components, reuses existing `placeholder.blade.php`
- **GeneralSettings** - `defaultRole`, `showIcons`, `saveGeneral()`; isolated state, own mount/render
- **BulkAssignDefaultRole** - reads default role from `ClearanceSettings` on each action (no shared state)
- **RoleMetaTable / GuardMetaTable** - `#[On('meta-saved')]` refresh; embed per-row `EditMeta`
- **EditMeta** - modal wrapper with `#[Locked]` subjectType/subjectKey, `modalName = 'edit-meta-{type}-{key}'`; `rules()` + `validationAttributes()`; `MetaService::update()` via method DI; `Flux::modal()->close()` on save; dispatches `meta-saved`
- **MetaService** - `update(string $type, string $key, array $data): ClearanceMeta`; sanitizes SVG via `SvgSanitizer`

### feat(roles): G2 - Roles family aligned to canonical Permissions style

**Files:** `src/Livewire/Roles/EditRole.php`, `src/Livewire/Roles/DeleteRole.php`, `src/Livewire/Roles/RoleForm.php`, `resources/views/livewire/roles/edit-role.blade.php`, `resources/views/livewire/roles/delete-role.blade.php`, `resources/views/livewire/roles/new-role.blade.php`, `resources/views/livewire/roles/role-form.blade.php`, `tests/Unit/T14RoleManagerTest.php`, `tests/Unit/T20LivewireRuntimeTest.php`

- **EditRole** - `#[Locked]` on `$roleId`, `$modalName = 'edit-role-{id}'` in `mount()`; removed `$showModal`/`onRoleSaved`
- **DeleteRole** - `#[Locked]` on `$roleId`, `$modalName` in `mount()`; removed `$showModal`; `confirmDelete()` no longer manually sets `$showModal = false`
- **RoleForm** - added `$modalName`, `Flux::modal()->close()` in `save()`, `validationAttributes()`; removed `cancel()` (replaced by `flux:modal.close` in view)
- **Views** - all 3 modals switched from `wire:model="showModal"` to named `flux:modal` pattern; cancel buttons use `flux:modal.close`; edit/delete triggers use `flux:button` with icon
- **T14, T20** - removed `cancel()` assertion; updated `EditRole`/`DeleteRole` tests for new `modalName` architecture

### feat(guards): G1 - Guards family aligned to canonical Permissions style

**Files:** `src/Livewire/Guards/EditGuard.php` (new), `src/Livewire/Guards/DeleteGuard.php`, `src/Livewire/Guards/GuardForm.php`, `src/Livewire/Guards/GuardManager.php`, `src/Services/GuardService.php`, `resources/views/livewire/guards/edit-guard.blade.php` (new), `resources/views/livewire/guards/delete-guard.blade.php`, `resources/views/livewire/guards/new-guard.blade.php`, `resources/views/livewire/guards/guard-form.blade.php`, `resources/views/livewire/guards/guard-manager.blade.php`, all 9 lang files, `tests/Unit/T12GuardManagerTest.php`

- **EditGuard** - new thin modal wrapper (`#[Locked]` guardId/guardName, sets `modalName = 'edit-guard-{id}'`), mirrors EditPermission
- **DeleteGuard** - typed-confirm delete (`confirmText === 'DELETE {name}'`), `#[Locked]` on name/guardId, dispatches `guard-deleted`
- **GuardForm** - removed `#[Rule]` attrs; replaced with `rules()` + `validationAttributes()`; method DI via `GuardService`; `?string $errorMessage`; `Flux::modal()->close()` on save; `abort_unless(canPerform('guards'), 403)`; removed `notify` dispatch
- **GuardManager** - added `public string $search`, in-memory array filtering per name/driver/provider
- **GuardService** - added `create()`, `update()`, `delete()` methods; added `id` field to DB guard map
- **new-guard.blade.php** - switched from `wire:model="showModal"` to named `flux:modal` pattern
- **guard-manager.blade.php** - search input, EditGuard + DeleteGuard buttons per DB guard row, `no_match` empty state
- **Lang** - added `guards.no_match`, `guards.search_placeholder`, `guards.delete.confirm_delete`; updated `guards.delete.desc` with typed-confirm instruction (all 9 languages)
- **T12** - extended with 14 new tests covering EditGuard/DeleteGuard/GuardForm lifecycle

### fix(cleanup): broken Role model, deprecated methods, stale test assertions

- Deleted `src/Models/Role.php` (wrong namespace, non-existent dependencies - dead code)
- Removed `PermissionManager::colorForGroup()` and `badgeTypeForAbility()` (`@deprecated`)
- `.output.txt` gitignored and unstaged
- Updated T13, T20 tests for new EditPermission/DeletePermission architecture (`#[Locked]` + `modalName` + `Flux::modal` pattern)
- Added `guards.form.driver_invalid` key to all 9 lang files

---

## [Unreleased] - 2026-05-01

### feat(users): UserPermissionManager - direct master-role assignment with guard-scoped permission editing

**Files:** `src/Livewire/Users/UserPermissionManager.php`, `resources/views/livewire/users/user-permission-manager.blade.php`, `routes/web.php`, all 9 lang files, `tests/Unit/T16UserRoleManagerTest.php`, `tests/Unit/T20LivewireRuntimeTest.php`

Replaced the obsolete `UserRoleManager` (contextual list) with a purpose-built per-user admin panel at `/clearance/user/{userId}` (route `clearance.user`). The panel:

- Assigns **master roles only** (roles not appearing as `child_role_id` in `clearance_role_hierarchy`) to a specific user via `$user->assignRole()`
- Renders one `flux:card` per assigned role showing all permissions for that guard: role-owned permissions are checked+disabled; remaining permissions are editable checkboxes persisted via `$user->givePermissionTo()` / `$user->revokePermissionTo()`
- **Remove role modal**: calls `$user->removeRole($role)` then revokes all directly assigned permissions for the same guard
- User resolved via `config('clearance.user_model') ?? config('auth.providers.users.model')` with eager loading of `roles.permissions` and `permissions`
- Security: `hasRole()` guard before any permission mutation; slave-role assignment blocked server-side
- Route always registered (no feature flag); `modules.users` key removed from config
- `UserRoleManager.php` and `user-role-manager.blade.php` deleted
- All 9 lang files updated with `user_permissions` translation key (en, it, ar, es, fr, hi, pt, ru, zh)
- 190 tests pass

---

## [Unreleased] - 2026-04-29

### feat(ui): modal-based forms for all sections - Permissions, Roles, Users, Hierarchy

**Files:** `PermissionManager.php`, `RoleManager.php`, `UserRoleManager.php`, `HierarchyManager.php`, all 6 view files

Replaced inline expand/collapse form panels with `flux:modal` dialogs across all four sections. Each manager dispatches browser events (`open-*`, `close-*`) to control modals via Alpine `x-on:event.window="show()"`. Form views redesigned as self-contained modal content using Flux UI components (`flux:input`, `flux:checkbox`, `flux:select`, `flux:button`, `flux:heading`, `flux:separator`). Modal content wrapped in `.clearance` div to preserve scoped CSS after Flux teleport. Delete-confirm panels converted to modals. `UserRoleManager` gained `openAssignForm()`/`closeAssignForm()`; `HierarchyManager` gained `openAddRelation()`/`closeAddRelation()`/`closeOverrideForm()`. 184 tests pass.

---

## [Unreleased] - 2026-04-28

### feat(ui): safe typed-delete confirmation for PermissionManager and RoleManager (T25, V8)

**Files:** `PermissionManager.php`, `RoleManager.php`, `permission-manager.blade.php`, `role-manager.blade.php`

`delete(string $prefix)` and `delete(int $id)` now stage the record for confirmation instead of deleting immediately. User must type `DELETE {name}` to confirm. Role deletion additionally blocked when `users_count > 0` - shows count warning. All writes via service layer (V8). 184 tests pass.

---

### feat(ui): RoleManager search, permission/user counts, pagination (T24)

**Files:** `RoleManager.php`, `role-manager.blade.php`

Added `WithPagination`, live search (`wire:model.live.debounce`), Perms and Users count columns (computed in `render()` via Eloquent + DB pivot). Empty state message adapts to search term. 184 tests pass.

---

### feat(ui): PermissionForm redesign - group prefix + CRUD checkboxes + custom pills (T23, V6, V8)

**Files:** `PermissionService.php`, `PermissionForm.php`, `permission-form.blade.php`

Added `createGroup(prefix, guard, abilities[])` and `updateGroup(prefix, guard, old[], new[])` to `PermissionService`. Form replaced: single-name input → prefix input + 5 CRUD checkboxes + Alpine pill list for custom abilities. `updateGroup` diffs and applies changes atomically via service layer. Naming convention (V6) enforced per ability. 184 tests pass.

---

### feat(ui): PermissionManager grouped view with search and pagination (T22, V6)

**Files:** `PermissionManager.php`, `permission-manager.blade.php`

Flat permission table replaced by prefix-grouped cards with colour-coded ability badges (emerald=CRUD, red=destructive, amber=custom). Live search filters whole groups. `WithPagination` paginates groups via `LengthAwarePaginator`. 184 tests pass.

---

### feat(ui): self-contained Blade components registered under clearance:: namespace (T26)

**Files:** `resources/views/components/{message,clipboard,validation-errors}.blade.php`, `ClearanceServiceProvider.php`

Three Alpine.js-based UI components extracted and registered via `Blade::anonymousComponentPath()`. No FontAwesome, no external JS dependencies. Available as `x-clearance::message`, `x-clearance::clipboard`, `x-clearance::validation-errors`. 184 tests pass.

---

### refactor(layout): remove standalone layout - Clearance components now use host app layout

**Files modified:**
- `src/Livewire/Guards/GuardManager.php` - removed `#[Layout]` attribute + `use Livewire\Attributes\Layout`
- `src/Livewire/Hierarchy/HierarchyManager.php` - same
- `src/Livewire/Permissions/PermissionManager.php` - same
- `src/Livewire/Roles/RoleManager.php` - same
- `src/Livewire/Users/UserRoleManager.php` - same
- `config/clearance.php` - added `'layout' => null` key
- `routes/web.php` - added `$applyLayout` closure that calls `->layout($layout)` on each route when `clearance.layout` is non-null

**What and why:** All five full-page Livewire components were decorated with `#[Layout('clearance::layouts.app')]`, forcing every request to render inside Clearance's own standalone HTML shell (own `<html>`, Tailwind CDN, separate header). This made Clearance visually inconsistent with the host application. Pattern aligned with Lingua package: no `#[Layout]` on components, Livewire falls back to the host app's configured default layout (`config('livewire.layout')`, typically `components.layouts.app`). The `clearance.layout` config key allows explicit override without touching components. 180 tests, 515 assertions - all pass.

---

### fix(install): guard HasRoles trait check before givePermissionTo() on --user flag

**Files modified:**
- `src/Commands/ClearanceInstallCommand.php` - `assignToUser()` now calls `class_uses_recursive()` to verify the User model uses `Spatie\Permission\Traits\HasRoles` before calling `givePermissionTo()`; emits two actionable `warn()` lines and returns early if trait is absent

**What and why:** `clearance:install --user=1` was throwing `BadMethodCallException: Call to undefined method App\Models\User::givePermissionTo()` when the User model did not use the `HasRoles` trait. The install command cannot add the trait itself (it belongs to the host app), so instead it detects the missing trait via `class_uses_recursive()`, warns the developer with the exact `use` statement to add, and skips the assignment cleanly. Re-running with `--force` after adding the trait will complete the assignment. 6 existing tests still pass.

---

### fix(install): auto-install Spatie Permission migrations when roles table absent

**Files modified:**
- `src/Commands/ClearanceInstallCommand.php` - added `ensureSpatieInstalled()` private method; added `Illuminate\Support\Facades\Schema` import

**What and why:** `clearance:install` was failing on fresh Laravel apps that had never run Spatie's migrations. The `create_clearance_role_hierarchy_table` stub has `foreign('parent_role_id')->on('roles')` - if the `roles` table does not exist the migration throws a DB exception. Fix adds a pre-check via `Schema::hasTable('roles')` before running clearance migrations. If absent: publishes the `permission-migrations` tag from `Spatie\Permission\PermissionServiceProvider` and calls `artisan migrate` to create all Spatie tables first. If `roles` already exists (Spatie previously installed) the method returns immediately - no duplicate migration. 6 existing install command tests still pass.

---

### feat(facade): implement Clearance class + facade (canIn, resolveFor, guards)

**Files modified/created:**
- `src/Clearance.php` - implemented: `canIn()`, `resolveFor()`, `guards()` thin wrappers over `ContextService` + `GuardService`; constructor DI auto-resolved by container
- `tests/Unit/T21ClearanceTest.php` - 8 tests: container resolution, facade resolution, `guards()`, `canIn()` false/true/guard-filter, `resolveFor()` with/without permissions

**What and why:** `Clearance.php` was an empty stub. Now exposes a clean PHP API for contextual permission checks without requiring direct service injection. `Facades/Clearance` was already registered - just needed the underlying class to be real. `Clearance::getFacadeRoot()` confirmed to return `Clearance` instance. 8 tests, 10 assertions.

---

### fix(model): RoleHierarchy::overrides() relationship + coverage 45% → 89%

**Files modified/created:**
- `src/Models/RoleHierarchy.php` - added `overrides(): HasMany` to `RolePermissionOverride` (real bug: `HierarchyManager::loadData()` used `with(['overrides.permission'])` without this relation)
- `tests/Unit/T20LivewireRuntimeTest.php` - 44 runtime tests for all 6 Livewire components via direct instantiation + `app()->call()`
- `tests/Unit/T5ModelsTest.php` - added `RolePermissionOverride::parentRole()/childRole()` test
- `tests/Unit/T6HierarchyServiceTest.php` - added `HierarchyService::removeOverride()` test
- `tests/Unit/T9InstallCommandTest.php` - added `--user` not-found path covering `assignToUser` null branch

**What and why:** Coverage was 45.8%; all Livewire components had 0% runtime coverage (T12–T16 use source inspection only). T20 instantiates each component and exercises business logic directly - state mutation, error paths before dispatch, DB writes. Also fixed `RoleHierarchy` missing `overrides()` relation. Coverage: **88.9%** (172 tests, 505 assertions, 0 failures).

---

### T19 - docs: README + install docs (complete package documentation)

**Files created:**
- `README.md`

**What and why:** Adds the package README with installation guide, configuration reference, permission naming convention docs, `@canin` directive usage, role hierarchy explanation, contextual roles (`modules.users`) docs, and database table reference. Covers I.install, I.config, I.canin, I.routes.

---

### T18 - test(feature): Pest feature tests - routes, middleware, @canin, DB compat (V1,V7,V10,I.install)

**Files created:**
- `tests/Feature/T18FeatureTest.php`

**Files modified:**
- `tests/TestCase.php` - added `app.key` to `getEnvironmentSetUp()` to support HTTP feature tests

**What and why:** Feature test suite covering: route registration (all five named clearance routes present and prefixed), middleware enforcement (unauthenticated requests redirected per V1), V7 (`permission.teams` stays `false`), V10 idempotency (marker file preserved on repeat install, `--force` overrides), I.install `--role` flag, @canin directive compilation and DB-backed resolution (V4 context isolation), and SQLite DB compat (all four clearance tables exist after migrations). 12 tests, 30 assertions.

---

### T17 - test(unit): Pest invariant tests - all services (V1,V2,V3,V4,V5,V6,V7,V8,V9)

**Files created:**
- `tests/Unit/T17ServiceInvariantsTest.php`

**What and why:** Dedicated invariant validation suite covering gaps not tested in T3–T8. V5: migration stubs verified to only `Schema::create` clearance-prefixed tables - no Spatie core tables created or altered. V8: source scan confirms all Livewire component PHP files contain zero direct Spatie write calls (`Role::create`, `Permission::create`, `givePermissionTo`, `revokePermissionTo`). V1: `RequireClearanceAccess` source verified to use `->can()` not `hasRole()`. V2+V9 integration: full cross-service flow (assign perm to parent → create hierarchy → add forced_on override → revoke perm → cleanup → override gone). V3: three-level chain rejected. V4: context isolation by `context_type` and `user_id`. V6: naming convention accepts and rejects correct patterns. V7: `permission.teams` config is falsy. 10 tests, 98 assertions.

---

## [Unreleased] - 2026-04-27

### T16 - feat(livewire): UserRoleManager optional panel - server-side manager scope (V4,V8,I.Users)

**Files created:**
- `src/Livewire/Users/UserRoleManager.php`
- `resources/views/livewire/users/user-role-manager.blade.php`
- `tests/Unit/T16UserRoleManagerTest.php`

**Files modified:**
- `routes/web.php` - `/users` route wired to `UserRoleManager::class` (only when `modules.users = true`)

**What and why:** Implements the optional `modules.users` panel for contextual user-role assignment. The component has two modes: admin mode (full view of all `UserRoleContext` records) and manager mode (scoped to the manager's own context). Mode is determined server-side in `resolveManagerScope()` which queries `UserRoleContext` for the authenticated user - if a record exists, that context becomes the scope (V4). Manager mode cannot assign or revoke outside their own `context_type`+`context_id` - enforced in both `assign()` and `revoke()` with explicit error messages. All writes target `UserRoleContext` (Clearance-owned table) via `firstOrCreate` and `delete()` - no Spatie model methods called (V8). `availableRoles` is loaded from Spatie's `Role` model (read-only query) but no writes to Spatie tables occur. Route is only registered when `config('clearance.modules.users', false)` is truthy.

---

### T15 - feat(livewire): HierarchyManager panel (V2,V3,V9,V8,I.Hierarchy)

**Files created:**
- `src/Livewire/Hierarchy/HierarchyManager.php`
- `resources/views/livewire/hierarchy/hierarchy-manager.blade.php`
- `tests/Unit/T15HierarchyManagerTest.php`

**Files modified:**
- `routes/web.php` - `/hierarchy` route wired to `HierarchyManager::class`
- `CHANGELOG.md` - backfill T1–T15

**What and why:** Implements the hierarchy management panel that enforces the single-level parent→child role constraint (V3). All write operations (createRelation, deleteRelation, addOverride, removeOverride) route through `HierarchyService`, which throws `ClearanceHierarchyViolationException` on V3 violations and `ClearanceInvalidOverrideException` on V2 violations. The component surfaces an override drill-down per hierarchy entry showing forced_on/forced_off overrides with per-permission badges (emerald = forced_on, red = forced_off). Orphan role badges identify roles that have no hierarchy relationships. V9 auto-cleanup (forced_on overrides deleted when parent loses permission) is handled entirely in HierarchyService::deleteRelation - the UI just calls the service. No direct Spatie model calls in component (V8).

---

### T14 - feat(livewire): RoleManager + RoleForm (V8,I.Roles)

**Files created:**
- `src/Livewire/Roles/RoleManager.php`
- `src/Livewire/Roles/RoleForm.php`
- `resources/views/livewire/roles/role-manager.blade.php`
- `resources/views/livewire/roles/role-form.blade.php`
- `tests/Unit/T14RoleManagerTest.php`

**Files modified:**
- `routes/web.php` - `/roles` route wired to `RoleManager::class`

**What and why:** `RoleManager` lists all Spatie roles enriched with `RoleMeta` data (is_system, is_protected). Protected roles have the Delete button suppressed at UI level. `RoleForm` provides create/edit with guard-scoped permission checkboxes loaded from `Permission::where('guard_name', $guard)`. All Spatie writes (create role, rename, syncPermissions) go through `RoleService` (V8). `RoleMeta` badge flags (is_system, is_protected) are persisted via `RoleMeta::updateOrCreate` - this is a Clearance-owned table, not a Spatie call, so V8 is not violated. Guard change triggers `updatedGuardName()` which reloads the permission list for the new guard.

---

### T13 - feat(livewire): PermissionManager + PermissionForm (V6,V8,I.Permissions)

**Files created:**
- `src/Livewire/Permissions/PermissionManager.php`
- `src/Livewire/Permissions/PermissionForm.php`
- `resources/views/livewire/permissions/permission-manager.blade.php`
- `resources/views/livewire/permissions/permission-form.blade.php`
- `tests/Unit/T13PermissionManagerTest.php`

**Files modified:**
- `routes/web.php` - `/permissions` route wired to `PermissionManager::class`

**What and why:** Full CRUD for Spatie permissions routed through `PermissionService` (V8). `PermissionManager` uses `colorForGroup(string $group): string` - a deterministic `crc32` hash mapped to a 10-color Tailwind palette - so all permissions sharing the same group prefix always display the same badge color. Copy-to-clipboard is a vanilla JS `navigator.clipboard.writeText()` inline onclick, requiring no additional dependencies. `PermissionForm` catches `ClearanceNamingException` from `PermissionService::validate()` and surfaces the error inline (V6). The `permission-saved` Livewire event is dispatched on save or cancel to let `PermissionManager` refresh its list and hide the form.

---

### T12 - feat(livewire): GuardManager read-only screen (V8,I.Guards)

**Files created:**
- `src/Livewire/Guards/GuardManager.php`
- `resources/views/livewire/guards/guard-manager.blade.php`
- `tests/Unit/T12GuardManagerTest.php`

**Files modified:**
- `routes/web.php` - `/guards` route wired to `GuardManager::class`; placeholder closures removed
- `tests/Feature/.gitkeep` - created to satisfy Pest `->in('Feature', 'Unit')` config

**What and why:** Guards are read-only config-derived data - no write operations exist. `GuardManager::mount()` injects `GuardService` and loads `all()` into a public property. The view renders a table of guard name/driver/provider. No Spatie calls anywhere (V8). The component is a full-page Livewire component using `#[Layout('clearance::layouts.app')]`.

---

### T11 - feat(views): clearance::layouts.app self-contained layout (I.routes)

**Files created:**
- `resources/views/layouts/app.blade.php`
- `tests/Unit/T11LayoutTest.php`

**What and why:** Panel layout must be self-contained - it cannot depend on the host application's layout (`@extends('layouts.app')` is explicitly absent). Tailwind 4 is loaded via the `@tailwindcss/browser@4` CDN script so no host app CSS pipeline is needed. Livewire scripts are loaded via standard `@livewireStyles` / `@livewireScripts` directives. Flux UI scripts are conditionally loaded only if `config('clearance.ui.flux_pro')` is truthy or `\Flux\Flux::pro()` returns true - avoiding errors when Flux is not installed. Uses `{{ $slot }}` for Livewire full-page component rendering.

---

### T10 - feat(blade): @canin/@endcanin Blade directives (V4,I.canin,I.ContextService)

**Files modified:**
- `src/ClearanceServiceProvider.php` - added `Blade::directive('canin', ...)` and `Blade::directive('endcanin', ...)` in `bootingPackage()`
- `tests/Unit/T10CaninDirectiveTest.php` - 3 compilation tests

**What and why:** `@canin($permission, $model)` compiles to `<?php if(app(\Rivalex\Clearance\Services\ContextService::class)->hasPermissionIn(auth()->user(), $permission, $model)): ?>`. Resolves `ContextService` from the IoC container on each directive invocation - no global state cached (V4). `@endcanin` compiles to `<?php endif; ?>`. The directive is registered at package boot, available in all Blade templates once the package is loaded.

---

### T9 - feat(commands): clearance:install Artisan command (V1,V10,I.install)

**Files created:**
- `src/Commands/ClearanceInstallCommand.php`
- `tests/Unit/T9InstallCommandTest.php`
- `tests/Feature/.gitkeep`

**Files modified:**
- `src/ClearanceServiceProvider.php` - `hasCommand(ClearanceInstallCommand::class)` + `runsMigrations()`

**What and why:** Install command is idempotent via a `.clearance-installed` marker file in `storage/` (V10). Second run without `--force` outputs a skip message and exits early. `--force` bypasses the marker. Publishes config and migrations via `vendor:publish`, then calls `artisan migrate` wrapped in try-catch (handles table-already-exists when developer re-runs after manual migration). Creates the `clearance-access` permission (or `config('clearance.access_permission')`) via `Permission::firstOrCreate`. `--user=ID` assigns the permission directly to a user; `--role=NAME` creates/finds the role and assigns via `PermissionService::assignToRole()` (V1 - ensures panel is accessible after install).

---

### T8 - feat(middleware): RequireClearanceAccess + routes (V1,I.middleware,I.routes)

**Files created:**
- `src/Http/Middleware/RequireClearanceAccess.php`
- `routes/web.php`
- `tests/Unit/T8MiddlewareTest.php`

**Files modified:**
- `src/ClearanceServiceProvider.php` - `aliasMiddleware('clearance.access', ...)` + `hasRoute('web')`

**What and why:** Middleware checks `$request->user()?->can($permission)` where `$permission` comes from `config('clearance.access_permission', 'clearance-access')`. Uses `can()` not `hasRole()` per V1. Returns HTTP 403 (via `abort(403)`) for unauthorized or unauthenticated users. Routes use configurable prefix (`config('clearance.route_prefix')`) and merge base middleware with `clearance.access` alias.

---

### T7 - feat(services): ContextService (V4,I.ContextService)

**Files created:**
- `src/Services/ContextService.php`
- `tests/Support/FakeUser.php`
- `tests/Support/FakeContext.php`
- `tests/Unit/T7ContextServiceTest.php`

**What and why:** `resolveFor($user, $model)` looks up `UserRoleContext` by `user_id`, `context_type` (class name of model), and `context_id` (model PK). Returns the permissions of the matched role merged with any `RolePermissionOverride` entries (forced_on adds, forced_off removes). Server-side scope enforcement: query always filters by all three keys - no data from other contexts leaks (V4). `hasPermissionIn($user, $permission, $model)` is a convenience wrapper over `resolveFor`.

---

### T6 - feat(services): HierarchyService (V2,V3,V9,I.HierarchyService)

**Files created:**
- `src/Services/HierarchyService.php`
- `src/Exceptions/ClearanceHierarchyViolationException.php`
- `src/Exceptions/ClearanceInvalidOverrideException.php`
- `tests/Unit/T6HierarchyServiceTest.php`

**What and why:** `createRelation($parent, $child)` throws `ClearanceHierarchyViolationException` if parent is already a child of any role, or child is already a parent of any role (V3 - single-level). `addOverride($hierarchy, $permission, 'forced_on')` throws `ClearanceInvalidOverrideException` if the parent role does not have the permission (V2). `deleteRelation()` calls `cleanupForcedOnOverrides()` which deletes all `forced_on` overrides referencing permissions the parent role no longer has (V9). `removeOverride()` deletes a single override.

---

### T5 - feat(models): RoleMeta, RoleHierarchy, RolePermissionOverride, UserRoleContext (V5,I.migrations)

**Files created:**
- `src/Models/RoleMeta.php`
- `src/Models/RoleHierarchy.php`
- `src/Models/RolePermissionOverride.php`
- `src/Models/UserRoleContext.php`
- `tests/Unit/T5ModelsTest.php`

**What and why:** Four Clearance-owned Eloquent models that extend Spatie's schema without touching it (V5). `RoleMeta` stores `is_system` and `is_protected` booleans per Spatie role. `RoleHierarchy` stores `parent_role_id`/`child_role_id` FK pairs with cascade deletes. `RolePermissionOverride` stores `override_type` enum (`forced_on`/`forced_off`) per hierarchy+permission pair. `UserRoleContext` stores `user_id`, `context_type`, `context_id`, `role_id` for contextual role assignments. All FKs reference `roles`/`permissions` tables with `ON DELETE CASCADE`.

---

### T4 - feat(services): RoleService (V8,I.PermissionService)

**Files created:**
- `src/Services/RoleService.php`
- `tests/Unit/T4RoleServiceTest.php`

**What and why:** `create(name, guardName)`, `rename(role, name)`, `delete(role)`, `syncPermissions(role, permissionNames[])`. `syncPermissions` resolves each permission name via `Permission::where('name', ...)->where('guard_name', $role->guard_name)`, rejects permissions from different guards (guard-scoped enforcement), then calls Spatie's `$role->syncPermissions()`. Livewire components must not call these directly - they inject `RoleService` (V8).

---

### T3 - feat(services): PermissionService (V6,V8,I.PermissionService)

**Files created:**
- `src/Services/PermissionService.php`
- `src/Exceptions/ClearanceNamingException.php`
- `tests/Unit/T3PermissionServiceTest.php`

**What and why:** `validate(string $name)` enforces `gruppo-azione` format via regex `/^[a-z][a-z0-9]*([-][a-z][a-z0-9]*)+$/` (V6). Throws `ClearanceNamingException` on violation. `create`, `rename`, `delete`, `assignToRole`, `revokeFromRole` are the only paths that mutate the `permissions` and `role_has_permissions` tables (V8). `groupFor(name)` extracts the prefix before the separator.

---

### T2 - feat(services): GuardService (I.config,I.Guards)

**Files created:**
- `src/Services/GuardService.php`
- `tests/Unit/T2GuardServiceTest.php`

**What and why:** `all()` returns guard configs from `config('auth.guards')` filtered to those listed in `config('clearance.guards')` when the override is non-empty, otherwise returns all. `has(guardName)` checks membership. Injected into Livewire components and install command so they always use the configured guard set.

---

### T1 - feat(scaffold): ClearanceServiceProvider, config, 4 migrations (V5,I.config,I.migrations)

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

**What and why:** Package foundation. `ClearanceServiceProvider` extends `PackageServiceProvider` from `spatie/laravel-package-tools`. All 4 migration stubs use `.stub` extension (package convention) and create only `clearance_*` tables - Spatie core tables (`roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions`) are never touched (V5). Config keys cover all §I.config surface. Test infrastructure uses Orchestra Testbench with a `runMigrations()` helper that directly includes `.stub` files (bypassing `artisan migrate`) because Testbench cannot auto-discover `.stub` extension migrations.
