<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Livewire\Roles;

use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;
use Rivalex\Clearance\Clearance;
use Rivalex\Clearance\Models\ClearanceMeta;
use Rivalex\Clearance\Models\ClearanceSettings;
use Rivalex\Clearance\Models\RoleMeta;
use Spatie\Permission\Models\Role;

/**
 * List screen for roles with search and pagination (V8).
 * Create/edit/delete handled by child modal components (NewRole, EditRole, DeleteRole).
 */
#[Lazy]
class RoleManager extends Component
{
    use WithPagination;

    public string $search = '';

    /**
     * No-op mount kept for test/Livewire lifecycle compatibility.
     */
    public function mount(): void {}

    /**
     * Reset pagination when search changes.
     */
    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    /**
     * Re-render after a child modal saves or deletes.
     */
    #[On('role-saved')]
    #[On('role-deleted')]
    public function refresh(): void {}

    public function placeholder(): View
    {
        return view('clearance::livewire.roles.placeholder');
    }

    public function render(): View
    {
        abort_unless(app(Clearance::class)->canAccess(), 403);

        $query = Role::orderBy('name');

        if ($this->search !== '') {
            $query->where('name', 'like', '%'.$this->search.'%');
        }

        $roles = $query->paginate(15);

        $metas = RoleMeta::whereIn('role_id', $roles->pluck('id')->all())
            ->get()
            ->keyBy('role_id');

        $clearanceMetas = ClearanceMeta::where('subject_type', 'role')
            ->whereIn('subject_key', $roles->pluck('name')->all())
            ->get()
            ->keyBy('subject_key');

        $showIcons = filter_var(ClearanceSettings::get('show_icons', '1'), FILTER_VALIDATE_BOOLEAN);

        $modelHasRolesTable = config('permission.table_names.model_has_roles', 'model_has_roles');

        $roleData = $roles->through(fn (Role $role) => [
            'role'              => $role,
            'meta'              => $metas->get($role->id),
            'clearance_meta'    => $clearanceMetas->get($role->name),
            'permissions_count' => $role->permissions()->count(),
            'users_count'       => DB::table($modelHasRolesTable)->where('role_id', $role->id)->count(),
        ]);

        return view('clearance::livewire.roles.role-manager', compact('roleData', 'showIcons'));
    }
}
