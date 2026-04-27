# Changelog

All notable changes to `clearance` will be documented in this file.

## [Unreleased]

### Added

- **T15** `feat(livewire)`: HierarchyManager — parent→child CRUD, override drill-down, orphan badges (V2,V3,V9,V8)
- **T14** `feat(livewire)`: RoleManager + RoleForm — guard-scoped permissions, is_system/is_protected badges via RoleMeta (V8)
- **T13** `feat(livewire)`: PermissionManager + PermissionForm — CRUD, group color badges, copy-to-clipboard (V6,V8)
- **T12** `feat(livewire)`: GuardManager — read-only guard listing screen (V8,I.Guards)
- **T11** `feat(views)`: clearance::layouts.app — self-contained panel layout, Tailwind 4 CDN, conditional Flux UI (I.routes)
- **T10** `feat(blade)`: @canin/@endcanin directives via ContextService::hasPermissionIn — server-side context scope (V4,I.canin)
- **T9** `feat(commands)`: clearance:install — publish config/migrations, migrate, create permission, --user/--role/--force flags, idempotent marker (V1,V10,I.install)
- **T8** `feat(middleware)`: RequireClearanceAccess — can() check, 403 on fail; route registration under configurable prefix (V1,I.middleware,I.routes)
- **T7** `feat(services)`: ContextService — resolveFor($user,$model), hasPermissionIn, override resolution (V4,I.ContextService)
- **T6** `feat(services)`: HierarchyService — createRelation, deleteRelation, addOverride, removeOverride, V9 auto-cleanup (V2,V3,V9,I.HierarchyService)
- **T5** `feat(models)`: RoleMeta, RoleHierarchy, RolePermissionOverride, UserRoleContext Eloquent models (V5,I.migrations)
- **T4** `feat(services)`: RoleService — CRUD, guard-scoped syncPermissions via PermissionService (V8,I.PermissionService)
- **T3** `feat(services)`: PermissionService — CRUD, gruppo-azione naming validation, single write path (V6,V8,I.PermissionService)
- **T2** `feat(services)`: GuardService — auto-detect guards from auth.guards + config override (I.config,I.Guards)
- **T1** `feat(scaffold)`: ClearanceServiceProvider, config/clearance.php, 4 migration stubs (V5,I.config,I.migrations)
