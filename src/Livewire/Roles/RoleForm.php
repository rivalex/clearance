<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Livewire\Roles;

use Flux\Flux;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Component;
use Rivalex\Clearance\Clearance;
use Rivalex\Clearance\Models\Permission;
use Rivalex\Clearance\Models\RoleMeta;
use Rivalex\Clearance\Services\GuardService;
use Rivalex\Clearance\Services\RoleService;
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

    /** @var array<string, array{group: string, abilities: array<int, array{id: int, name: string, ability: string, color: string, selected: bool, locked: bool, out_of_ceiling: bool}>}> */
    public array $permissionGroups = [];

    public ?int $roleId = null;

    /** Ceiling parent role ID (null = no ceiling). */
    public ?int $parentRoleId = null;

    /** @var array<int, string> role id => name, same guard, non-child roles */
    public array $availableParentRoles = [];

    public string $modalName = '';

    public ?string $errorMessage = null;

    public bool $isSuperAdmin = false;

    /**
     * Load role data or defaults.
     */
    public function mount(GuardService $guardService, ?int $roleId = null): void
    {
        $this->availableGuards = array_keys($guardService->all());
        $this->guardName = config('auth.defaults.guard', 'web');
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
                $this->isSuperAdmin = $role->name === 'super_admin';
                $this->guardName = $role->guard_name;

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
        return [
            'name' => ['required', 'string', 'min:1', 'max:255'],
            'guardName' => ['required', 'string'],
        ];
    }

    public function validationAttributes(): array
    {
        return [
            'name' => __('clearance::ui.roles.form.role_name'),
            'guardName' => __('clearance::ui.common.guard'),
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
            foreach ($group['abilities'] as $ability) {
                if ($ability['selected']) {
                    $selected[] = $ability['name'];
                }
            }
        }
        $this->loadPermissions($this->guardName, $selected);
    }

    /**
     * Save role via RoleService; update RoleMeta for badges (V8).
     */
    public function save(RoleService $roleService): void
    {
        abort_unless(app(Clearance::class)->canPerform('roles'), 403);
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

        // Only trust permission IDs that belong to the role's guard (prevents cross-guard injection)
        $allowedIds = Permission::where('guard_name', $this->guardName)
            ->pluck('id')
            ->flip()
            ->all();

        $selectedPermissions = [];
        foreach ($this->permissionGroups as $group) {
            foreach ($group['abilities'] as $ability) {
                if (($ability['selected'] || $ability['locked']) && isset($allowedIds[$ability['id']])) {
                    $perm = Permission::find($ability['id']);
                    if ($perm) {
                        $selectedPermissions[] = $perm;
                    }
                }
            }
        }

        try {
            if ($this->roleId === null) {
                $role = $roleService->create($this->name, $this->guardName);
            } else {
                /** @var Role|null $role */
                $role = Role::find($this->roleId);

                if ($role === null) {
                    return;
                }

                if (! $this->isSuperAdmin) {
                    $roleService->rename($role, $this->name);
                }
                $role = $role->fresh();
            }

            $roleService->syncPermissions(auth()->user(), $role, $selectedPermissions);

            // RoleMeta is a Clearance-owned table - not a Spatie call (V8 compliant)
            RoleMeta::updateOrCreate(
                ['role_id' => $role->id],
                [
                    'is_locked' => $this->isSuperAdmin ? true : $this->isLocked,
                    'scope' => $this->scope,
                    'context_types' => $this->scope === RoleMeta::SCOPE_CONTEXTUAL ? $this->contextTypes : null,
                ],
            );

            // Ceiling parent — I1/I2/I3 enforced via service.
            if (! $this->isSuperAdmin) {
                if ($this->parentRoleId !== null) {
                    $parentRole = Role::find($this->parentRoleId);
                    if ($parentRole !== null) {
                        $roleService->setParent($role, $parentRole);
                    }
                } else {
                    $roleService->removeParent($role);
                }
            }
        } catch (\Throwable $e) {
            $this->errorMessage = $e->getMessage();

            return;
        }

        if ($this->roleId === null) {
            // Modal is reused (static Livewire key), so the form must reset itself after a successful create.
            $this->reset(['name', 'isLocked', 'scope', 'contextTypes', 'parentRoleId']);
            $this->guardName = config('auth.defaults.guard', 'web');
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
        return view('clearance::livewire.roles.role-form');
    }

    /**
     * Build available parent role options: same guard, not self, not already a child.
     * One bulk query for the child-exclusion set (no per-row isParent() call).
     */
    private function loadParentOptions(string $guard): void
    {
        // Roles that already have a parent cannot themselves act as a parent (I3).
        $childRoleIds = RoleMeta::whereNotNull('parent_role_id')->pluck('role_id')->all();

        $query = Role::where('guard_name', $guard)
            ->where('name', '!=', 'super_admin')
            ->whereNotIn('id', $childRoleIds);

        if ($this->roleId !== null) {
            $query->where('id', '!=', $this->roleId);
        }

        $this->availableParentRoles = $query->pluck('name', 'id')->all();
    }

    /**
     * Load permission checkboxes scoped to the given guard.
     *
     * @param  array<int, string>  $selected
     */
    private function loadPermissions(string $guard, array $selected): void
    {
        $permissions = Permission::where('guard_name', (string) $guard)->get();

        $isSuperAdmin = $this->roleId !== null
            && Role::find($this->roleId)?->name === 'super_admin';

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
                'locked' => $isSuperAdmin && str_starts_with($p->name, 'clearance-'),
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
