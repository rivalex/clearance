# Security Review Report — rivalex/clearance
**Date:** 2026-05-18  
**Reviewer:** Security Review Agent (everything-claude-code:security-reviewer)  
**Files reviewed:** 52 source files (src/, resources/views/, config/, routes/)  
**Tests at review time:** 222/222 passing

---

## Findings Summary

| ID | Severity | File | Issue | Status |
|----|----------|------|-------|--------|
| C1 | CRITICAL | `Dashboard.php:33`, `PermissionManager.php:72` | `naming_separator` interpolated raw into SQL | ⬜ TODO |
| H1 | HIGH | `delete-permission.blade.php:11`, `delete-guard.blade.php:11` | Unescaped `$prefix`/`$name` in `{!! !!}` | ⬜ TODO |
| H2 | HIGH | `SvgSanitizer.php:32` + 8 Blade files | SVG `style` attribute not inspected — CSS XSS vector | ⬜ TODO |
| H3 | HIGH | `permission-form.blade.php:5` | Unlocked `$editingPrefix` rendered unescaped | ⬜ TODO |
| H4 | HIGH | All Livewire components | `render()` has no auth check | ⬜ TODO |
| H5 | HIGH | `AssignRoleModal.php:100` | No self-escalation guard | ⬜ TODO |
| M1 | MEDIUM | `Clearance.php:69` | `canPerform()` bypasses auth when `runningUnitTests()` | ⬜ TODO |
| M2 | MEDIUM | `AssignRoleModal.php:117`, `ContextualRolesPanel.php:91` | `$contextClass` FQCN not validated against whitelist | ⬜ TODO |
| M3 | MEDIUM | `GuardForm.php:45` | Guard `name` accepts any characters | ⬜ TODO |
| M4 | MEDIUM | `routes/web.php:23` | Asset route unauthenticated — SVGs served without auth | ⬜ TODO |
| M5 | MEDIUM | `DeleteRole.php:64–121` | User reassignment runs BEFORE typed-confirm check | ⬜ TODO |
| L1 | LOW | `PermissionManager.php:73` | Search string unbounded (no max length) | ⬜ TODO |
| L2 | LOW | `RoleForm.php:39` | `$permissionGroups` unlocked — client can inject permission IDs | ⬜ TODO |
| L3 | LOW | `ClearanceInstallCommand.php:78` | Only `clearance-users-write` seeded — others fallback to broad access | ⬜ TODO |
| L4 | LOW | `config/clearance.php:129` | `super_admin_gate_bypass` defaults `true` — app-wide bypass opt-out | ⬜ TODO |

---

## Detailed Findings

### C1 — CRITICAL: SQL Injection via `naming_separator`
**Files:** `src/Livewire/Dashboard.php:33`, `src/Livewire/Permissions/PermissionManager.php:59–72`

Boot-time validation of `naming_separator` in `ClearanceServiceProvider::bootingPackage()` uses regex `/^[\-_]$/` and throws `ClearanceConfigException` if invalid. However, `config()` values can be mutated at runtime. Any code path that calls `config(['clearance.naming_separator' => "'; DROP ..."])` after boot bypasses the guard. The raw value is then interpolated into `DB::raw()` and `selectRaw()`.

**Fix:** Re-validate at call site before interpolation. Throw if not matching `/^[\-_]$/`.

---

### H1 — HIGH: XSS in delete confirmation views
**Files:** `resources/views/livewire/permissions/delete-permission.blade.php:11`, `resources/views/livewire/guards/delete-guard.blade.php:11`

`$prefix` and `$name` are `#[Locked]` but originate from the database. An attacker who can create a permission/guard with a malicious name can trigger stored XSS in another admin's delete dialog.

**Fix:** Wrap `$prefix`/`$name` in `e()` inside the `{!! !!}` block.

---

### H2 — HIGH: SvgSanitizer allows `style` attribute — CSS XSS vector
**Files:** `src/Support/SvgSanitizer.php:32`, rendered via `{!! $meta->icon_svg !!}` in 8 Blade views

`ALLOWED_ATTRS` includes `style`. Values like `style="background:url(javascript:alert(1))"` pass through undetected.

**Fix:** In `SvgSanitizer::cleanAttributes()`, strip `style` attributes whose value matches `url\s*\(|expression\s*\(|javascript:` (case-insensitive). Or remove `style` from `ALLOWED_ATTRS` entirely.

---

### H3 — HIGH: Unlocked `$editingPrefix` rendered unescaped
**File:** `resources/views/livewire/permissions/permission-form.blade.php:5`

`PermissionForm::$editingPrefix` is a plain `public string` without `#[Locked]`. In Livewire 4, a crafted state payload can set it to arbitrary HTML, which is rendered unescaped in the heading.

**Fix:** Add `#[Locked]` to `PermissionForm::$editingPrefix` OR wrap in `e()` in the view.

---

### H4 — HIGH: `render()` has no authorization check
**Files:** All Livewire components

Write actions correctly call `abort_unless(canPerform(...), 403)`. But `render()` has no guard. Direct POST to `/livewire/update` exposes all rendered data to any authenticated user who bypasses route middleware.

**Fix:** Add auth check to `mount()` in every component:
```php
public function mount(): void
{
    abort_unless(auth()->user()?->can(config('clearance.access_permission', 'clearance-access')), 403);
}
```

---

### H5 — HIGH: No self-escalation guard in `AssignRoleModal`
**File:** `src/Livewire/Users/AssignRoleModal.php:100`

Only the exact string `super_admin` is blocked. Any panel user with `clearance-users-write` can assign themselves any other elevated role.

**Fix:** Only `super_admin` holders can assign roles to themselves.

---

### M1 — MEDIUM: `canPerform()` bypasses auth in test environments
**File:** `src/Clearance.php:69–71`

```php
if (app()->runningUnitTests() && $user === null) {
    return true;
}
```

Misconfigured staging with `APP_ENV=testing` becomes an open admin panel. Same pattern exists in `PermissionForm::save()`.

**Fix:** Remove bypass. Use `$this->actingAs($user)` in tests.

---

### M2 — MEDIUM: `$contextClass` FQCN not validated against whitelist
**Files:** `src/Livewire/Users/AssignRoleModal.php:117`, `src/Livewire/Users/ContextualRolesPanel.php:91`

`$contextClass` is `#[Locked]` but never validated against `config('clearance.contextual_models')`. If set to an arbitrary FQCN, `::all()` or `::findOrFail()` can enumerate any Eloquent model.

**Fix:** Validate against config whitelist on `mount()` and before each use.

---

### M3 — MEDIUM: Guard `name` accepts any characters
**File:** `src/Livewire/Guards/GuardForm.php:45`

No character-set restriction on the `name` field. Names are injected into `auth.guards` config.

**Fix:** Add `'regex:/^[a-z0-9_\-]+$/'` to validation rules.

---

### M4 — MEDIUM: Asset route serves SVGs without authentication
**File:** `routes/web.php:23`

The `/clearance/assets/{path}` route uses only `['web']` middleware. SVG files served with `Content-Type: image/svg+xml` to unauthenticated users. Path traversal correctly blocked via `realpath()`.

**Fix:** Add `auth` middleware if assets are only needed inside the panel, or document the intentional choice.

---

### M5 — MEDIUM: `DeleteRole` mutates DB before typed-confirm check
**File:** `src/Livewire/Roles/DeleteRole.php:64–121`

User reassignment/detachment (lines 64–118) runs before the `confirmText` check at line 121. Wrong confirmation text still executes destructive user-detachment.

**Fix:** Move `confirmText` check to immediately after the `is_locked` check (~line 44), before any DB mutation.

---

### L1 — LOW: Unbounded search string
**File:** `src/Livewire/Permissions/PermissionManager.php:73`

No max-length on `$search`. Parameterized (no injection), but wastes DB resources.

**Fix:** Add `max:100` validation or truncate in `updatingSearch()`.

---

### L2 — LOW: `$permissionGroups` unlocked in `RoleForm`
**File:** `src/Livewire/Roles/RoleForm.php:39`

Client can inject arbitrary permission IDs. `save()` uses `$ability['id']` to call `Permission::find()`, allowing selection of permissions from a different guard.

**Fix:** Add `#[Locked]` to `$permissionGroups`.

---

### L3 — LOW: Only `clearance-users-write` seeded
**File:** `src/Commands/ClearanceInstallCommand.php:78`

Other capabilities (`permissions`, `roles`, `guards`, `settings`) fall back to broad `clearance-access` permission.

**Fix:** Seed all fine-grained permissions or document the implicit full-write behavior clearly.

---

### L4 — LOW: `super_admin_gate_bypass` defaults `true`
**File:** `config/clearance.php:129`

Enables app-wide Gate bypass by default. Any `super_admin` user bypasses all application Policies and Gates, not just Clearance ones.

**Fix:** Default to `false`. Document opt-in clearly.

---

## Implementation Plan

### Phase 1 — Quick Wins (1-line fixes, no architecture change)

- [ ] **P1.1** — H3: Add `#[Locked]` to `PermissionForm::$editingPrefix`
- [ ] **P1.2** — H1: Wrap `$prefix` in `e()` in `delete-permission.blade.php:11`
- [ ] **P1.3** — H1: Wrap `$name` in `e()` in `delete-guard.blade.php:11`
- [ ] **P1.4** — M5: Move `confirmText` check above DB mutations in `DeleteRole::confirmDelete()`
- [ ] **P1.5** — M3: Add `regex:/^[a-z0-9_\-]+$/` to `GuardForm` name validation
- [ ] **P1.6** — L1: Truncate/validate `$search` max 100 chars in `PermissionManager`
- [ ] **P1.7** — L4: Change `super_admin_gate_bypass` default to `false` in config

### Phase 2 — Security Logic Fixes

- [ ] **P2.1** — C1: Re-validate `naming_separator` at call site in `Dashboard.php` and `PermissionManager.php`
- [ ] **P2.2** — H2: Extend `SvgSanitizer::cleanAttributes()` to reject dangerous `style` values
- [ ] **P2.3** — H4: Add `mount()` auth check to all Livewire components
- [ ] **P2.4** — H5: Add self-escalation guard to `AssignRoleModal::save()`
- [ ] **P2.5** — M2: Validate `$contextClass` against config whitelist in both components
- [ ] **P2.6** — L2: Add `#[Locked]` to `RoleForm::$permissionGroups`

### Phase 3 — Architecture & Config

- [ ] **P3.1** — M1: Remove `runningUnitTests()` bypass from `Clearance.php` and `PermissionForm.php`; update affected tests
- [ ] **P3.2** — M4: Add `auth` middleware to asset route (or document intentional public access)
- [ ] **P3.3** — L3: Seed all fine-grained permissions in `ClearanceInstallCommand`

### Phase 4 — Tests & Changelog

- [ ] **P4.1** — Add security regression tests for C1, H1, H3, M5, H5
- [ ] **P4.2** — Update CHANGELOG.md with security patch entry
- [ ] **P4.3** — Run full test suite — target 222+/222 passing

---

## Execution Log

| Step | Status | Notes |
|------|--------|-------|
| P1.1 | ⬜ | |
| P1.2 | ⬜ | |
| P1.3 | ⬜ | |
| P1.4 | ⬜ | |
| P1.5 | ⬜ | |
| P1.6 | ⬜ | |
| P1.7 | ⬜ | |
| P2.1 | ⬜ | |
| P2.2 | ⬜ | |
| P2.3 | ⬜ | |
| P2.4 | ⬜ | |
| P2.5 | ⬜ | |
| P2.6 | ⬜ | |
| P3.1 | ⬜ | |
| P3.2 | ⬜ | |
| P3.3 | ⬜ | |
| P4.1 | ⬜ | |
| P4.2 | ⬜ | |
| P4.3 | ⬜ | |
