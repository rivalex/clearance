<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Livewire;

use Illuminate\Database\Eloquent\Model;
use Illuminate\View\View;
use Livewire\Attributes\Lazy;
use Livewire\Component;
use Rivalex\Clearance\Clearance;
use Rivalex\Clearance\Concerns\HasClearanceLayout;
use Rivalex\Clearance\Models\Permission;
use Rivalex\Clearance\Models\UserRoleContext;
use Rivalex\Clearance\Services\GuardService;
use Rivalex\Clearance\Support\Identity;
use Rivalex\Clearance\Support\SpatieSchema;
use Spatie\Permission\Models\Role;

#[Lazy]
class Dashboard extends Component
{
    use HasClearanceLayout;

    public function placeholder(): View
    {
        return $this->withClearanceLayout(view('clearance::livewire.dashboard-placeholder'));
    }

    public function render(): View
    {
        abort_unless(app(Clearance::class)->canAccess(), 403);

        $stats = [
            'roles_count' => Role::count(),
            'guards_count' => count(app(GuardService::class)->all()),
            'permissions_count' => Permission::count(),
            // Same grouping as PermissionForm/HasPermissionGroups::getPermissionGroupAttribute() - keep in sync.
            'groups_count' => Permission::all()->pluck('permission_group')->unique()->count(),
            'user_contexts_count' => UserRoleContext::count(),
        ];

        // Holders per role on the role pivot, counted for the identity user model only (top 5).
        $userModel = Identity::userModel();
        $morphClass = is_string($userModel) && class_exists($userModel) && is_subclass_of($userModel, Model::class)
            ? (new $userModel)->getMorphClass()
            : null;
        $pivot = SpatieSchema::table('model_has_roles');
        $roleKey = SpatieSchema::rolePivotKey();
        $roleModel = new Role;
        $roleTable = $roleModel->getTable();
        $roleId = $roleTable.'.'.$roleModel->getKeyName();

        $users_per_role = Role::query()
            ->select($roleTable.'.*')
            ->selectSub(static function ($sub) use ($pivot, $roleKey, $roleId, $morphClass): void {
                $sub->from($pivot)->selectRaw('count(*)')->whereColumn($pivot.'.'.$roleKey, $roleId);
                $morphClass === null ? $sub->whereRaw('1 = 0') : $sub->where($pivot.'.model_type', $morphClass);
            }, 'users_count')
            ->orderByDesc('users_count')
            ->orderBy($roleId)
            ->take(5)
            ->get();

        return $this->withClearanceLayout(view('clearance::livewire.dashboard', [
            'stats' => $stats,
            'users_per_role' => $users_per_role,
        ]));
    }
}
