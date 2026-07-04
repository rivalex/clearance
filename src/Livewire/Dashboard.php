<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Livewire;

use Illuminate\View\View;
use Livewire\Attributes\Lazy;
use Livewire\Component;
use Rivalex\Clearance\Clearance;
use Rivalex\Clearance\Concerns\HasClearanceLayout;
use Rivalex\Clearance\Models\Permission;
use Rivalex\Clearance\Models\UserRoleContext;
use Rivalex\Clearance\Services\GuardService;
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

        // Dati per un grafico o tabella veloce: utenti per ruolo (top 5)
        $users_per_role = Role::withCount('users')
            ->orderBy('users_count', 'desc')
            ->take(5)
            ->get();

        return $this->withClearanceLayout(view('clearance::livewire.dashboard', [
            'stats' => $stats,
            'users_per_role' => $users_per_role,
        ]));
    }
}
