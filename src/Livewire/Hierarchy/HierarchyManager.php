<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Livewire\Hierarchy;

use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Rivalex\Clearance\Exceptions\ClearanceHierarchyViolationException;
use Rivalex\Clearance\Exceptions\ClearanceInvalidOverrideException;
use Rivalex\Clearance\Models\RoleHierarchy;
use Rivalex\Clearance\Models\RolePermissionOverride;
use Rivalex\Clearance\Services\HierarchyService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Manages parent→child hierarchy relations and override drill-down (V2,V3,V9,V8).
 */
#[Layout('clearance::layouts.app')]
class HierarchyManager extends Component
{
    /** @var array<int, \Rivalex\Clearance\Models\RoleHierarchy> */
    public array $hierarchies = [];

    /** @var array<int, \Spatie\Permission\Models\Role> */
    public array $orphanRoles = [];

    /** @var array<int, \Spatie\Permission\Models\Role> */
    public array $allRoles = [];

    /** @var array<int, \Spatie\Permission\Models\Permission> */
    public array $allPermissions = [];

    public ?int    $drilldownId     = null;
    public bool    $showAddRelation = false;
    public ?int    $newParentId     = null;
    public ?int    $newChildId      = null;
    public ?string $errorMessage    = null;

    public bool   $showOverrideForm    = false;
    public ?int   $overrideHierarchyId = null;
    public ?int   $overridePermissionId = null;
    public string $overrideType        = 'forced_on';

    /**
     * Load hierarchy state on mount.
     */
    public function mount(): void
    {
        $this->loadData();
    }

    /**
     * Add a parent→child relation via HierarchyService (V2, V3, V8).
     */
    public function addRelation(HierarchyService $hierarchyService): void
    {
        $this->errorMessage = null;

        $parent = $this->newParentId ? Role::find($this->newParentId) : null;
        $child  = $this->newChildId  ? Role::find($this->newChildId)  : null;

        if ($parent === null || $child === null) {
            $this->errorMessage = 'Select both parent and child roles.';

            return;
        }

        try {
            $hierarchyService->createRelation($parent, $child);
        } catch (ClearanceHierarchyViolationException $e) {
            $this->errorMessage = $e->getMessage();

            return;
        }

        $this->showAddRelation = false;
        $this->newParentId     = null;
        $this->newChildId      = null;
        $this->loadData();
    }

    /**
     * Remove a hierarchy relation via HierarchyService (V9, V8).
     */
    public function removeRelation(int $id, HierarchyService $hierarchyService): void
    {
        $relation = RoleHierarchy::find($id);

        if ($relation !== null) {
            $hierarchyService->deleteRelation($relation);
        }

        if ($this->drilldownId === $id) {
            $this->drilldownId = null;
        }

        $this->loadData();
    }

    /**
     * Toggle override drill-down for a hierarchy relation.
     */
    public function drilldown(int $id): void
    {
        $this->drilldownId      = ($this->drilldownId === $id) ? null : $id;
        $this->showOverrideForm = false;
    }

    /**
     * Open override form for a given hierarchy.
     */
    public function openOverrideForm(int $hierarchyId): void
    {
        $this->overrideHierarchyId  = $hierarchyId;
        $this->overridePermissionId = null;
        $this->overrideType         = 'forced_on';
        $this->showOverrideForm     = true;
    }

    /**
     * Add an override via HierarchyService (V2, V9, V8).
     */
    public function addOverride(HierarchyService $hierarchyService): void
    {
        $this->errorMessage = null;

        $hierarchy  = $this->overrideHierarchyId  ? RoleHierarchy::find($this->overrideHierarchyId)  : null;
        $permission = $this->overridePermissionId ? Permission::find($this->overridePermissionId) : null;

        if ($hierarchy === null || $permission === null) {
            $this->errorMessage = 'Select a hierarchy and permission.';

            return;
        }

        try {
            $hierarchyService->addOverride($hierarchy, $permission, $this->overrideType);
        } catch (ClearanceInvalidOverrideException $e) {
            $this->errorMessage = $e->getMessage();

            return;
        }

        $this->showOverrideForm = false;
        $this->loadData();
    }

    /**
     * Remove an override via HierarchyService (V8).
     */
    public function removeOverride(int $overrideId, HierarchyService $hierarchyService): void
    {
        $override = RolePermissionOverride::find($overrideId);

        if ($override !== null) {
            $hierarchyService->removeOverride($override);
        }

        $this->loadData();
    }

    public function render(): View
    {
        return view('clearance::livewire.hierarchy.hierarchy-manager');
    }

    private function loadData(): void
    {
        $this->hierarchies    = RoleHierarchy::with(['parentRole', 'childRole', 'overrides.permission'])
            ->get()->all();

        $allRoles             = Role::orderBy('name')->get();
        $this->allRoles       = $allRoles->all();
        $this->allPermissions = Permission::orderBy('name')->get()->all();

        $usedIds = RoleHierarchy::selectRaw('parent_role_id as role_id')
            ->union(RoleHierarchy::selectRaw('child_role_id as role_id'))
            ->pluck('role_id')
            ->unique()
            ->all();

        $this->orphanRoles = $allRoles->whereNotIn('id', $usedIds)->values()->all();
    }
}
