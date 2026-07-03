# Changelog

All notable changes to `rivalex/clearance` will be documented in this file.
Format follows [Conventional Commits](https://conventionalcommits.org) and [Keep a Changelog](https://keepachangelog.com/).

---

## [1.0.0] - 2026-07-03

First public release. `rivalex/clearance` is a Livewire 4 + Flux UI admin panel for managing permissions, roles, and contextual authorization on top of `spatie/laravel-permission`, installable via a single artisan command.

### Requirements

PHP `^8.3` · Laravel `^11.0|^12.0|^13.0` · `spatie/laravel-permission ^6.0` · `livewire/livewire ^3.0|^4.0` · `livewire/flux ^2.14`.

### Added

- **Admin panel** — Livewire 4 + Flux UI components for Permissions, Roles, Guards, Settings, and a Dashboard, mountable as pre-built routes (`/clearance/*`) or as individually embeddable Livewire components.
- **Contextual authorization** — role and permission checks scoped to a specific model instance (e.g. a Store, Tenant, or Project): `$user->canIn()`, `$user->hasRoleIn()` (via the `HasClearance` trait), the `@canin`/`@hasrolein` Blade directives, and the `ContextService`/`Clearance` facade for use outside a User model context. Resolution merges contextual-role grants with global-role grants, then applies per-context `forced_on`/`forced_off` overrides.
- **Ceiling roles** — a role can declare a parent role whose permission set acts as an upper bound (`RoleMeta.parent_role_id`). Child permissions exceeding the parent's ceiling are silently trimmed; a role cannot be both a parent and a child (single-level enforcement).
- **Fine-grained write permissions** — one `clearance-{section}-write` permission per panel section (`permissions`, `roles`, `guards`, `settings`, `users`), seeded automatically by the installer and independent from the coarse `clearance-access` read permission.
- **Super Admin** — an automatically-provisioned `super_admin` role synced with all `clearance-*` permissions, with alias detection for pre-existing admin-like roles during install, and an opt-in `Gate::before()` bypass (`super_admin_gate_bypass`, default `false`).
- **Users module** (opt-in via `modules.users`) — per-user panel for global and contextual role assignment, plus per-context permission overrides (`clr_ctx_overrides`), scoped to a specific context model instance.
- **Settings panel** — per-role/guard display metadata (name, description, sanitized SVG icon, color), a configurable default role with optional auto-assignment on registration and bulk-assignment to existing users, and a `show_icons` toggle.
- **Guards management** — database-backed guards injected into `auth.guards` at boot, restricted to an allow-listed set of drivers.
- **`clearance:install` command** — publishes config and migrations, detects and bootstraps `spatie/laravel-permission` if absent, seeds all `clearance-*` permissions and the `super_admin` role, and supports `--user`, `--role`, `--super-admin-role`, and `--force`.
- **`clearance:backfill` command** — adopts Clearance into an existing Spatie installation: seeds display metadata and role scope for pre-existing roles, and imports guards from `config/auth.php`; supports `--only` and `--dry-run`.
- **i18n** — 9 bundled languages (ar, en, es, fr, hi, it, pt, ru, zh) with an identical key set across all locales, enforced by a dedicated parity test.
- **Extensibility** — `HasClearance` trait (contextual auth methods, includes Spatie's `HasRoles`), `HasPermissionGroups` trait (permission-group UI accessors for custom `Permission` subclasses), `ClearanceContextable` contract (custom context labels), and reusable Blade components (`x-clearance::message`, `clipboard`, `validation-errors`, icon set).
- **SVG sanitization** — `SvgSanitizer`, an allow-list-based sanitizer (tags, attributes, `on*` handlers, `javascript:` URIs, CSS injection vectors) applied to every user-supplied icon before storage and at render time.

### Security

This release ships after a dedicated pre-publication security audit and two follow-up hardening passes. Highlights:

- Per-context `forced_off` overrides are deny-authoritative — they now win even when the same permission is also granted via a global role (previously the override could be silently defeated).
- Privilege-escalation ceilings on every path that grants a permission: an actor cannot grant themselves a permission, cannot grant a permission they don't themselves hold, and cannot touch `clearance-*` permissions — enforced both for direct per-user grants and for role-level permission syncing.
- `clearance-*` permissions are protected end-to-end: they cannot be renamed, deleted, or created/added to any role outside the package's own install flow.
- `@canin`/`@hasrolein` fail closed (return `false`, not a 500) for unauthenticated guests.
- `naming_separator` is validated at boot against a strict allow-list, closing the only path by which it reaches raw SQL fragments.
- Migrations that depend on Spatie's `roles`/`permissions` tables fail with an actionable message if run before `clearance:install`, instead of a cryptic foreign-key error.
- No known vulnerable dependencies (`composer audit` clean).

Full audit trail, findings, and fixes: [`docs/plans/security-audit/plan.md`](docs/plans/security-audit/plan.md).

### Fixed

- Reconciled i18n key drift (French locale was missing keys used by the live UI).
- Removed an orphaned `Hierarchy` Livewire module left over from an earlier design (superseded by ceiling roles) that referenced deleted classes.
- Fixed a broken static-analysis configuration that had been silently preventing `composer analyse` from running.
- Clarified the install-time warning shown when a `User` model is missing `HasClearance`/`HasRoles`.

### Changed / Breaking

- `super_admin_gate_bypass` now defaults to `false` (previously `true`). Applications relying on the global Gate bypass must opt in explicitly.
- `ContextService::resolveFor()` deny-override semantics: a `forced_off` override now always denies, even against a global-role grant (see Security above).
- `RoleService::syncPermissions()` and `UserClearanceService::syncGlobalExtraPermissions()`/`syncContextualExtraPermissions()` gained a required leading `Authenticatable $actor` parameter to enforce the privilege-escalation ceiling. Any code calling these services directly must pass the acting user.
- Minimum PHP raised to `^8.3`; Laravel `10.x` support dropped, `13.x` added.
