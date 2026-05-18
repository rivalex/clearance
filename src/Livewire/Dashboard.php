<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Livewire;

use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\Lazy;
use Livewire\Component;
use Rivalex\Clearance\Clearance;
use Rivalex\Clearance\Models\Permission;
use Rivalex\Clearance\Models\UserRoleContext;
use Rivalex\Clearance\Services\GuardService;
use Spatie\Permission\Models\Role;

#[Lazy]
class Dashboard extends Component
{
    public function placeholder(): View
    {
        return view('clearance::livewire.dashboard-placeholder');
    }

    public function render(): View
    {
        abort_unless(app(Clearance::class)->canAccess(), 403);

        $separator = config('clearance.naming_separator', '-');
        if (! preg_match('/^[\-_]$/', (string) $separator)) {
            $separator = '-';
        }

        $stats = [
            'roles_count' => Role::count(),
            'guards_count' => count(app(GuardService::class)->all()),
            'permissions_count' => Permission::count(),
            'groups_count' => (function () use ($separator): int {
                $driver = DB::connection()->getDriverName();
                $expr = $driver === 'sqlite'
                    ? DB::raw("DISTINCT SUBSTR(name, 1, INSTR(name, '{$separator}') - 1)")
                    : DB::raw("DISTINCT LEFT(name, POSITION('{$separator}' IN name) - 1)");
                return DB::table('permissions')
                    ->select($expr)
                    ->where('name', 'like', "%{$separator}%")
                    ->count() ?: Permission::distinct('name')->count();
            })(),
            'user_contexts_count' => UserRoleContext::count(),
        ];

        // Dati per un grafico o tabella veloce: utenti per ruolo (top 5)
        $users_per_role = Role::withCount('users')
            ->orderBy('users_count', 'desc')
            ->take(5)
            ->get();

        return view('clearance::livewire.dashboard', [
            'stats' => $stats,
            'users_per_role' => $users_per_role,
        ]);
    }
}
