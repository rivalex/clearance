<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Livewire\Roles;

use Flux\Flux;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Rivalex\Clearance\Clearance;
use Rivalex\Clearance\Exceptions\ClearanceNamingException;
use Rivalex\Clearance\Exceptions\ClearanceProtectedResourceException;
use Rivalex\Clearance\Exceptions\ClearanceScopeViolationException;
use Rivalex\Clearance\Models\Permission;
use Rivalex\Clearance\Models\RoleMeta;
use Rivalex\Clearance\Services\GuardService;
use Rivalex\Clearance\Services\PermissionService;
use Rivalex\Clearance\Services\RoleService;
use Rivalex\Clearance\Support\ErrorMessage;
use Rivalex\Clearance\Support\Identity;
use Rivalex\Clearance\Support\SuperAdmin;
use Spatie\Permission\Models\Role;

/**
 * Create / edit form for a single role (V8).
 * All Spatie writes go through RoleService.
 */
class RoleForm extends Component
{
    public string $name = '';

    public string $guardName = '';

    public bool $isLocked = false;

    public string $scope = 'global';

    /** @var array<int, string> FQCN strings of allowed context model types (contextual scope only). */
    public array $contextTypes = [];

    /** @var array<int, string> */
    public array $availableGuards = [];

    /** @var array<string, string> FQCN => label, from config('clearance.contextual_models'). */
    public array $availableContextTypes = [];

    /**
     * Built by the server as group => {group, abilities[{id, name, ability, color, selected, locked, out_of_ceiling}]},
     * but client-writable: every reader guards the shape.
     *
     * @var array<array-key, mixed>
     */
    public array $permissionGroups = [];

    #[Locked]
    public ?int $roleId = null;

    /** Name the role had when the form was loaded; rename() runs only when the name differs. */
    #[Locked]
    public string $originalName = '';

    /** Ceiling parent role ID (null = no ceiling). */
    public ?int $parentRoleId = null;

    /** @var array<int, string> role id => name, same guard, non-child roles */
    public array $availableParentRoles = [];

    #[Locked]
    public string $modalName = '';

    public ?string $errorMessage = null;

    #[Locked]
    public bool $isSuperAdmin = false;

    /** Whether the form opens read-only: the super_admin role viewed by an actor who is not super_admin. */
    #[Locked]
    public bool $readOnly = false;

    /**
     * Load role data or defaults.
     */
    public function mount(GuardService $guardService, ?int $roleId = null): void
    {
        $this->availableGuards = array_keys($guardService->all());
        $this->guardName = Identity::guard();
        $this->roleId = $roleId;

        // Build available context types from package config (FQCN => label).
        $this->availableContextTypes = collect(config('clearance.contextual_models', []))
            ->mapWithKeys(fn ($cfg, $fqcn) => [$fqcn => $cfg['label'] ?? class_basename($fqcn)])
            ->all();

        if ($roleId !== null) {
            /** @var Role|null $role */
            $role = Role::find($roleId);

            if ($role !== null) {
                $this->name = $role->name;
                $this->originalName = $role->name;
                $this->isSuperAdmin = $role->name === SuperAdmin::ROLE && $role->guard_name === SuperAdmin::guard();
                $this->guardName = $role->guard_name;
                $this->readOnly = $this->isSuperAdmin && ! SuperAdmin::is(auth()->user());

                $meta = RoleMeta::where('role_id', $role->id)->first();

                if ($meta !== null) {
                    $this->isLocked = $this->isSuperAdmin ? true : (bool) $meta->is_locked;
                    $this->scope = $meta->scope ?? RoleMeta::SCOPE_GLOBAL;
                    $this->contextTypes = $meta->context_types ?? [];
                    $this->parentRoleId = $meta->parent_role_id;
                }

                $rolePermissions = $role->permissions->pluck('name')->all();
                $this->loadParentOptions($role->guard_name);
                $this->loadPermissions($role->guard_name, $rolePermissions);

                return;
            }
        }

        $this->loadParentOptions($this->guardName);
        $this->loadPermissions($this->guardName, []);
    }

    public function rules(): array
    {
        $nameRules = ['required', 'string', 'min:1', 'max:255'];

        // Existing roles keep their current name even when it predates the character restriction.
        if ($this->roleId === null || $this->name !== $this->originalName) {
            $nameRules[] = 'regex:/^[\pL\pN _.-]{1,255}\z/u';
        }

        $guardRules = ['required', 'string'];

        // Edit mode never reads the client guard: save() uses the stored role's guard.
        if ($this->roleId === null) {
            $guardRules[] = Rule::in(array_keys(app(GuardService::class)->all()));
        }

        $guard = $this->resolveGuard();

        $nameRules[] = Rule::unique((string) config('permission.table_names.roles', 'roles'), 'name')
            ->where('guard_name', $guard)
            ->ignore($this->roleId);

        return [
            'name' => $nameRules,
            'guardName' => $guardRules,
            'scope' => ['required', Rule::in([RoleMeta::SCOPE_GLOBAL, RoleMeta::SCOPE_CONTEXTUAL])],
            'contextTypes' => ['array'],
            'contextTypes.*' => ['string'],
            'parentRoleId' => ['nullable', Rule::in(array_keys($this->parentOptions($guard)))],
        ];
    }

    /**
     * Guard the role is saved on: the stored role's guard in edit mode, the selected guard in create mode.
     */
    private function resolveGuard(): string
    {
        if ($this->roleId !== null) {
            $stored = Role::find($this->roleId);

            if ($stored !== null) {
                return $stored->guard_name;
            }
        }

        return $this->guardName;
    }

    public function validationAttributes(): array
    {
        return [
            'name' => __('clearance::ui.roles.form.role_name'),
            'guardName' => __('clearance::ui.common.guard'),
            'scope' => __('clearance::ui.roles.form.scope'),
        ];
    }

    /**
     * Reload permission checkboxes when guard changes; clear ceiling parent (different guard).
     */
    public function updatedGuardName(): void
    {
        $this->parentRoleId = null;
        $this->loadParentOptions($this->guardName);
        $this->loadPermissions($this->guardName, []);
    }

    /**
     * Recompute out_of_ceiling flags when parent selection changes.
     */
    public function updatedParentRoleId(): void
    {
        $selected = [];
        foreach ($this->permissionGroups as $group) {
            if (! is_array($group) || ! is_array($group['abilities'] ?? null)) {
                continue;
            }

            foreach ($group['abilities'] as $ability) {
                if (is_array($ability) && ($ability['selected'] ?? false) && is_string($ability['name'] ?? null)) {
                    $selected[] = $ability['name'];
                }
            }
        }
        $this->loadPermissions($this->guardName, $selected);
    }

    /**
     * Drop malformed client entries after any write to the permission checkboxes, so the view
     * and save() only ever see the shape loadPermissions() builds.
     */
    public function updatedPermissionGroups(): void
    {
        $clean = [];

        foreach ($this->permissionGroups as $groupKey => $group) {
            if (! is_array($group) || ! is_string($group['group'] ?? null) || ! is_array($group['abilities'] ?? null)) {
                continue;
            }

            $abilities = [];

            foreach ($group['abilities'] as $index => $ability) {
                if (
                    is_array($ability)
                    && is_int($ability['id'] ?? null)
                    && is_string($ability['name'] ?? null)
                    && is_string($ability['ability'] ?? null)
                    && is_string($ability['color'] ?? null)
                ) {
                    $abilities[$index] = $ability;
                }
            }

            $clean[$groupKey] = ['group' => $group['group'], 'abilities' => $abilities];
        }

        $this->permissionGroups = $clean;
    }

    /**
     * Save role via RoleService; update RoleMeta for badges (V8).
     */
    public function save(RoleService $roleService): void
    {
        abort_unless(app(Clearance::class)->canPerform('roles'), 403);

        $actor = auth()->user();
        abort_if($actor === null, 403);

        $this->errorMessage = null;

        try {
            $this->validate();
        } catch (ValidationException $e) {
            $this->errorMessage = $e->getMessage();
            if (app()->runningUnitTests()) {
                return;
            }
            throw $e;
        }

        $existing = null;

        if ($this->roleId !== null) {
            /** @var Role|null $existing */
            $existing = Role::find($this->roleId);

            if ($existing === null) {
                return;
            }

            // Recomputed here, never read from $readOnly: only a super_admin may save the super_admin role.
            if (
                SuperAdmin::isRole($existing)
                && $existing->guard_name === SuperAdmin::guard()
                && ! SuperAdmin::is($actor)
            ) {
                $this->errorMessage = __('clearance::ui.errors.super_admin_role_change');

                return;
            }

            // Only a super_admin may change a locked role.
            $lockedNow = (bool) RoleMeta::where('role_id', $existing->id)->value('is_locked');

            if ($lockedNow && ! SuperAdmin::is($actor)) {
                $this->errorMessage = __('clearance::ui.errors.role_locked_change');

                return;
            }
        }

        // The guard of an existing role is never taken from the client.
        $guard = $existing !== null ? $existing->guard_name : $this->guardName;

        // Only trust permission IDs that belong to the role's guard (prevents cross-guard injection)
        $allowedIds = Permission::where('guard_name', $guard)
            ->pluck('id')
            ->flip()
            ->all();

        $selectedPermissions = [];
        foreach ($this->permissionGroups as $group) {
            if (! is_array($group) || ! is_array($group['abilities'] ?? null)) {
                continue;
            }

            foreach ($group['abilities'] as $ability) {
                if (! is_array($ability)) {
                    continue;
                }

                $abilityId = $ability['id'] ?? null;

                if (! is_int($abilityId) && ! (is_string($abilityId) && ctype_digit($abilityId))) {
                    continue;
                }

                if (((bool) ($ability['selected'] ?? false) || (bool) ($ability['locked'] ?? false)) && isset($allowedIds[$abilityId])) {
                    $perm = Permission::find($abilityId);
                    if ($perm) {
                        $selectedPermissions[] = $perm;
                    }
                }
            }
        }

        try {
            // Rename, sync, meta and parent commit together or not at all.
            (new Role)->getConnection()->transaction(function () use ($roleService, $actor, $existing, $guard, $selectedPermissions): void {
                if ($existing === null) {
                    $role = $roleService->create($this->name, $guard);
                } else {
                    $role = $existing;

                    // One lock order everywhere: role rows in ascending key order, in one statement when two are needed.
                    if (! $this->isSuperAdmin && $this->parentRoleId !== null) {
                        get_class($existing)::query()
                            ->whereKey([$existing->getKey(), $this->parentRoleId])
                            ->orderBy($existing->getKeyName())
                            ->lockForUpdate()
                            ->get();
                    }

                    // Only a changed name is renamed; a locked role is saved without renaming it.
                    if (! $this->isSuperAdmin && $this->name !== $this->originalName) {
                        $roleService->renameAs($actor, $role, $this->name);
                    }
                    $role = $role->fresh();
                }

                $roleService->syncPermissions($actor, $role, $selectedPermissions);

                $roleService->updateMetaAs(
                    $actor,
                    $role,
                    $this->isSuperAdmin ? true : $this->isLocked,
                    $this->scope,
                    $this->scope === RoleMeta::SCOPE_CONTEXTUAL ? $this->contextTypes : null,
                );

                // Ceiling parent - I1/I2/I3 enforced via service.
                if (! $this->isSuperAdmin) {
                    if ($this->parentRoleId !== null) {
                        $parentRole = Role::find($this->parentRoleId);
                        if ($parentRole !== null) {
                            $roleService->setParentAs($actor, $role, $parentRole);
                        }
                    } else {
                        $roleService->removeParentAs($actor, $role);
                    }
                }
            });
        } catch (ValidationException|ClearanceScopeViolationException|ClearanceProtectedResourceException|ClearanceNamingException $e) {
            $this->errorMessage = ErrorMessage::for($e);

            return;
        }

        if ($this->roleId === null) {
            // Modal is reused (static Livewire key), so the form must reset itself after a successful create.
            $this->reset(['name', 'isLocked', 'scope', 'contextTypes', 'parentRoleId']);
            $this->guardName = Identity::guard();
            $this->loadParentOptions($this->guardName);
            $this->loadPermissions($this->guardName, []);
            $this->resetErrorBag();
            $this->resetValidation();
        }

        Flux::modal($this->modalName)->close();
        $this->dispatch('role-saved');
    }

    public function render(): View
    {
        abort_unless(app(Clearance::class)->canAccess(), 403);

        return view('clearance::livewire.roles.role-form');
    }

    /**
     * Build available parent role options: same guard, not self, not already a child.
     * One bulk query for the child-exclusion set (no per-row isParent() call).
     */
    private function loadParentOptions(string $guard): void
    {
        $this->availableParentRoles = $this->parentOptions($guard);
    }

    /**
     * Compute the parent role options (id => name) for a guard from the database.
     *
     * @return array<int, string>
     */
    private function parentOptions(string $guard): array
    {
        // Roles that already have a parent cannot themselves act as a parent (I3).
        $childRoleIds = RoleMeta::whereNotNull('parent_role_id')->pluck('role_id')->all();

        $query = Role::where('guard_name', $guard)
            ->where('name', '!=', SuperAdmin::ROLE)
            ->whereNotIn('id', $childRoleIds);

        if ($this->roleId !== null) {
            $query->where('id', '!=', $this->roleId);
        }

        return $query->pluck('name', 'id')->all();
    }

    /**
     * Load permission checkboxes scoped to the given guard.
     *
     * @param  array<int, string>  $selected
     */
    private function loadPermissions(string $guard, array $selected): void
    {
        $permissions = Permission::where('guard_name', (string) $guard)->get();

        $currentRole = $this->roleId !== null ? Role::find($this->roleId) : null;
        $isSuperAdmin = $currentRole !== null
            && $currentRole->name === SuperAdmin::ROLE
            && $currentRole->guard_name === SuperAdmin::guard();

        // Ceiling IDs: null means no ceiling (all permissions allowed).
        $ceilingIds = null;
        if ($this->parentRoleId !== null) {
            $parentRole = Role::find($this->parentRoleId);
            if ($parentRole !== null) {
                $ceilingIds = $parentRole->permissions->pluck('id')->flip()->all();
            }
        }

        $groups = [];
        $separator = config('clearance.naming_separator', '-');

        foreach ($permissions as $p) {
            $groupKey = $p->permission_group;
            if (! isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'group' => $p->group_string,
                    'abilities' => [],
                ];
            }

            $abilityName = str_replace("{$groupKey}{$separator}", '', $p->name);

            $groups[$groupKey]['abilities'][] = [
                'id' => $p->id,
                'name' => $p->name,
                'ability' => $abilityName,
                'color' => Permission::colorForAbility($abilityName),
                'selected' => in_array($p->name, $selected, true),
                'locked' => $isSuperAdmin && app(PermissionService::class)->isProtected($p->name),
                'out_of_ceiling' => $ceilingIds !== null && ! isset($ceilingIds[$p->id]),
            ];
        }

        // Sort abilities in each group
        foreach ($groups as $key => &$group) {
            usort($group['abilities'], function ($a, $b) {
                $order = ['create', 'read', 'update', 'delete'];
                $posA = array_search($a['ability'], $order);
                $posB = array_search($b['ability'], $order);

                if ($posA !== false && $posB !== false) {
                    return $posA <=> $posB;
                }
                if ($posA !== false) {
                    return -1;
                }
                if ($posB !== false) {
                    return 1;
                }

                return strcasecmp($a['ability'], $b['ability']);
            });
        }

        // Sort groups by key
        ksort($groups);

        $this->permissionGroups = $groups;
    }
}
